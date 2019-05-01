<?php

namespace AppBundle\Command;


use AppBundle\Entity\Address;
use AppBundle\Entity\Beneficiary;
use AppBundle\Entity\HelloassoPayment;
use AppBundle\Entity\Membership;
use AppBundle\Entity\Registration;
use AppBundle\Entity\User;
use AppBundle\Helper\Helloasso;
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
 * Class HelloAssoSyncroCommand
 *
 */
class HelloAssoSyncroCommand extends Command implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    private $campaign;

    protected function configure()
    {
        $this->setName('app:helloasso:syncro')
            ->setDescription("Synchronise les cotisations réalisées sur hello asso en les transformant en adhérent")
            ->addArgument('campaign', InputArgument::REQUIRED, 'identifiant de campagne');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $campaign = $input->getArgument('campaign');
        $this->campaign = $campaign;
        $helloassoHelper = $this
            ->container
            ->get(Helloasso::class);
        $campaignUrl = sprintf('campaigns/%s/payments', $campaign);
        $payments_json = $helloassoHelper
            ->get($campaignUrl, array('page' => 1));

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $m = $em->getRepository('AppBundle:Membership')->findOneBy(array(), array('member_number' => 'DESC'));
        $lastMembershipNumber = 0;
        if ($m) {
            $lastMembershipNumber = $m->getMemberNumber();
        }

        $superAdmin = $em->getRepository(User::class)->findByRole('ROLE_SUPER_ADMIN');
        $registrar = array_shift($superAdmin);

        $sfStyle = new SymfonyStyle($input, $output);
        $maxPage = $payments_json->pagination->max_page;

        for ($page = 1; $page <= $maxPage; $page++) {
            $payments_json = $helloassoHelper->get($campaignUrl, ['page' => $page]);
            $payments = $payments_json->resources;

            foreach ($payments as $payment) {
                if (null === $user = $em->getRepository(User::class)->findOneByEmail($payment->payer_email)) {
                    try {
                        $this->createNewMember($payment, $em, $registrar, ++$lastMembershipNumber);
                        $sfStyle->success(sprintf('new user %s created', $payment->payer_email));
                    } catch (HelloAssoPaymentAlreadyExistsException $e) {
                        $sfStyle->warning(sprintf('user %s has already a hello asso payment registered', $payment->payer_email));
                    }

                } else {
                    try {
                        $this->renewRegistration($payment, $user, $registrar, $em);
                        $sfStyle->success(sprintf('renew registration for %s', $payment->payer_email));
                    } catch (HelloAssoPaymentAlreadyExistsException $e) {
                        $sfStyle->warning(sprintf('user %s has already a hello asso payment registered', $payment->payer_email));
                    }
                }
            }
        }
    }

    private function createNewMember($payment, EntityManagerInterface $em, User $registrar, int $membershipNumber)
    {
        if ($payment->status !== 'AUTHORIZED') {
            return;
        }

        $member = new Membership();
        $member->setWithdrawn(false);
        $member->setFrozen(false);
        $member->setFrozenChange(false);
        $member->setMemberNumber($membershipNumber);

        $registration = $this->createRegistration($payment, $member, $em, $registrar);

        $member->setLastRegistration($registration);

        $address = new Address();
        $address->setStreet1($payment->payer_address);
        $address->setZipcode($payment->payer_zip_code);
        $address->setCity($payment->payer_city);

        $benificiary = new Beneficiary();
        $benificiary->setFirstname($payment->payer_first_name);
        $benificiary->setLastname($payment->payer_last_name);
        $benificiary->setAddress($address);

        $member->setMainBeneficiary($benificiary);

        $user = new User();
        $user->setEmail($payment->payer_email);
        $user->setUsername($this->generateUsername($benificiary));
        $user->setPassword(\bin2hex(\random_bytes(20)));

        $benificiary->setUser($user);
        $em->persist($member);

        $em->flush();
    }

    private function createRegistration($payment, Membership $member, EntityManagerInterface $em, User $registrar)
    {
        $exists = $em->getRepository('AppBundle:HelloassoPayment')->findOneBy(array('paymentId' => $payment->id));

        if ($exists) {
            throw new HelloAssoPaymentAlreadyExistsException();
        }

        $helloassoPayment = new HelloassoPayment();
        $date = new \DateTime();
        $date->setTimestamp(strtotime($payment->date));

        $helloassoPayment->setPaymentId($payment->id);
        $helloassoPayment->setDate($date);
        $helloassoPayment->setAmount($payment->amount);
        $helloassoPayment->setCampaignId($this->campaign);
        $helloassoPayment->setPayerFirstName($payment->payer_first_name);
        $helloassoPayment->setPayerLastName($payment->payer_last_name);
        $helloassoPayment->setStatus($payment->status);
        $helloassoPayment->setEmail($payment->payer_email);

        $registration = new Registration();
        $registration->setMembership($member);
        $registration->setDate($date);
        $registration->setAmount($this->getSubscriptionAmount($payment));
        $registration->setMode(Registration::TYPE_HELLOASSO);
        $registration->setHelloassoPayment($helloassoPayment);
        $registration->setRegistrar($registrar);

        return $registration;
    }

    private function getSubscriptionAmount($payment) {
        foreach ($payment->actions as $action) {
            if ($action->type === 'SUBSCRIPTION') {
                return $action->amount;
            }
        }
    }

    private function generateUsername(Beneficiary $beneficiary)
    {
        if (!$beneficiary->getFirstname() || !$beneficiary->getLastname()) {
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

    private function renewRegistration($payment, User $user, User $registrar, EntityManager $em)
    {
        $member = $user->getBeneficiary()->getMembership();

        $registration = $this->createRegistration($payment, $member, $em, $registrar);

        $member->setLastRegistration($registration);
        $em->flush();

    }

}

class HelloAssoPaymentAlreadyExistsException extends \Exception {}
