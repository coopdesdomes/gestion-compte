<?php
namespace AppBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Event\ShiftDeletedEvent;
use Symfony\Component\Console\Style\SymfonyStyle;

class DeleteShiftCommand extends Command implements ContainerAwareInterface
{
  use ContainerAwareTrait;

  protected function configure()
  {
      $this->setName('app:shift:delete')
          ->setDescription('supprime tous les créneaux existants');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $sfStyle = new SymfonyStyle($input, $output);
    $sfStyle->confirm('Êtes-vous sur de vouloir supprimer DÉFINITIVEMENT tous les créneaux ?');


    $em = $this->container->get('doctrine.orm.default_entity_manager');
    $dispatcher = $this->container->get('event_dispatcher');

    $shifts = $em->getRepository('AppBundle:Shift')->findAll();

    foreach ($shifts as $s) {
      $dispatcher->dispatch(ShiftDeletedEvent::NAME, new ShiftDeletedEvent($s));

      $em->remove($s);
    }

    $em->flush();
  }
}
