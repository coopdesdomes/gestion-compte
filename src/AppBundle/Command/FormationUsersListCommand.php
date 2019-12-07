<?php

namespace AppBundle\Command;

use AppBundle\Entity\Formation;
use AppBundle\Entity\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class FormationUsersListCommand extends Command implements ContainerAwareInterface
{
  use ContainerAwareTrait;

  protected function configure()
  {
      $this->setName('app:users:formation')
          ->setDescription(
<<<EOF
Add the contributeur formation to all users listed in file app/Resources/csv/liste-contributeurs.csv
EOF
);
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $em = $this->container->get('doctrine.orm.default_entity_manager');
    $contributeur = $em->getRepository(Formation::class)->findOneByName('Contributeur');
    $basePath = $this->container->getParameter('kernel.root_dir').'/Resources/csv';
    $listHandler = fopen($basePath.'/liste-contributeurs.csv', 'r+');
    $userRepository = $em->getRepository(User::class);

    while (false !== $data = fgetcsv($listHandler)) {
      dump($data[0]);
      $user = $userRepository->findOneByEmail($data[0]);
      dump($user);
      if ($user) {
        $user->getBeneficiary()->addFormation($contributeur);
        $em->persist($user);
        $em->flush();
      }
    }
  }

}
