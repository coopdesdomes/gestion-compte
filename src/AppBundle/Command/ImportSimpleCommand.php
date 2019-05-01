<?php

namespace AppBundle\Command;


use AppBundle\Entity\Address;
use AppBundle\Entity\Beneficiary;
use AppBundle\Entity\Membership;
use AppBundle\Entity\Registration;
use AppBundle\Entity\User;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
/**
 * Class ImportSimpleCommand
 *
 */
class ImportSimpleCommand extends Command implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    private $compta = [];
    private $export = [];

    protected function configure()
    {
        $this->setName('app:import:simple');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $basePath = $this->container->getParameter('kernel.root_dir').'/Resources/csv';
        $adherentHandle = fopen($basePath.'/adherent_2019.csv', 'r+');
        $comptaHandler = fopen($basePath.'/compta.csv', 'r+');
        $exporthandler = fopen($basePath.'/export_coop.csv', 'r+');

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $m = $em->getRepository('AppBundle:Membership')->findOneBy(array(), array('member_number' => 'DESC'));
        $lastMembershipNumber = 0;
        if ($m) {
            $lastMembershipNumber = $m->getMemberNumber();
        }

        $superAdmin = $em->getRepository(User::class)->findByRole('ROLE_SUPER_ADMIN');
        $registrar = array_shift($superAdmin);

        $sfStyle = new SymfonyStyle($input, $output);

        //load in memory compta and export
        while (false !== $data = fgetcsv($comptaHandler)) {
            $this->compta[] = $data;
        }
        fclose($comptaHandler);
        while(false !== $data = fgetcsv($exporthandler)) {
            $this->export[] = $data;
        }
        fclose($exporthandler);

        // remove first line
        fgetcsv($adherentHandle);

        while (false !== $data = fgetcsv($adherentHandle)) {
            $email = $data[2];
            if (null === $user = $em->getRepository(User::class)->findOneByEmail($email)) {
                $this->createNewMember($data, $em, $registrar, ++$lastMembershipNumber);
            }
        }
    }

    private function createNewMember(array $data, EntityManagerInterface $em, User $registrar, int $membershipNumber)
    {
        $member = new Membership();
        $member->setWithdrawn(false);
        $member->setFrozen(false);
        $member->setFrozenChange(false);
        $member->setMemberNumber($membershipNumber);

        $compta = $this->fetchCompta($data[1], $data[0]);
        $export = $this->fetchExport($data[2]);

        if (empty($compta)) {
            $date = new \DateTime('2019-01-01');
            $amount = 0;

            $registration = new Registration();
            $registration->setMembership($member);
            $registration->setDate($date);
            $registration->setAmount($amount);
            $registration->setMode(Registration::TYPE_CASH);
            $registration->setRegistrar($registrar);

            $member->addRegistration($registration);
        } else {
            foreach ($compta as $cpta) {
                $date = \DateTime::createFromFormat('d/m/Y', $cpta[1]);
                $amount = $cpta[5];

                $registration = new Registration();
                $registration->setMembership($member);
                $registration->setDate($date);
                $registration->setAmount($amount);
                $registration->setMode(Registration::TYPE_CASH);
                $registration->setRegistrar($registrar);

                $member->addRegistration($registration);
            }
        }

        $benificiary = new Beneficiary();
        $benificiary->setFirstname($data[0]);
        $benificiary->setLastname($data[1]);

        if (null !== $export) {
            $address = new Address();
            $address->setStreet1($export[3]);
            $address->setZipcode($export[4]);
            $address->setCity($export[5]);
            $benificiary->setAddress($address);
        }

        $member->setMainBeneficiary($benificiary);

        $user = new User();
        $user->setEmail($data[2]);
        $user->setUsername($this->generateUsername($benificiary));
        $user->setPassword(\bin2hex(\random_bytes(20)));

        $benificiary->setUser($user);
        $em->persist($member);

        $em->flush();
    }

    private function fetchCompta($firstname, $lastname)
    {
        $found = [];
        foreach ($this->compta as $compta) {
            if ($compta[4] === strtolower($lastname.' '.$firstname) || $compta[4] === strtolower($firstname.' '.$lastname)){
                $found[] = $compta;
            }
        }

        return $found;
    }

    private function fetchExport($email) {
        foreach ($this->export as $export) {
            if ($export[2] === $email) {
                return $export;
            }
        }

        return null;
    }

    private function generateUsername(Beneficiary $beneficiary)
    {
        if (!$beneficiary->getFirstname() && !$beneficiary->getLastname()) {
            return null;
        }
        $username = User::makeUsername($beneficiary->getFirstname(), $beneficiary->getLastname());
        $qb = $this->container->get('doctrine.orm.default_entity_manager')->createQueryBuilder();
        $usernames = $qb->select('u')->from('AppBundle\Entity\User', 'u')
            ->where($qb->expr()->like('u.username', $qb->expr()->literal($username . '%')))
            ->orderBy('u.username', 'DESC')
            ->getQuery()
            ->getResult();

        if (count($usernames)) {
            $count = 1;
            $first = $usernames[0]->getUsername();
            if(preg_match_all('/\d+/', $first, $numbers)) {
                $count = end($numbers[0]) + 1;
            }
            $username = $username . + $count;
        }
        return $username;
    }
}
