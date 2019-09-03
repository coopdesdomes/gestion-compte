<?php

namespace AppBundle\Command;

use AppBundle\Entity\Formation;
use AppBundle\Entity\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class ActivateUsersCommand extends Command implements ContainerAwareInterface
{
  use ContainerAwareTrait;

  protected function configure()
  {
      $this->setName('app:users:activate')
          ->setDescription("Enable all users and add contributeur formation");
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $em = $this->container->get('doctrine.orm.default_entity_manager');
    $userManipulator = $this->container->get('fos_user.util.user_manipulator');
    $contributeur = $em->getRepository(Formation::class)->findOneByName('Contributeur');
    $users = $em->getRepository(User::class)->findByEnabled(false);

    foreach ($users as $user) {
      $userManipulator->activate($user->getUsername());
      $user->getBeneficiary()->addFormation($contributeur);
      $em->persist($user);
      $em->flush();
    }
  }
}
