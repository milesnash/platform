<?php

namespace Oro\Bundle\NavigationBundle\Command;

use Oro\Bundle\UserBundle\Entity\User;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class ResetMenuUpdatesCommand
 * Console command implementation
 *
 * @package Oro\Bundle\NavigationBundle\Command
 */
class ResetMenuUpdatesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this
            ->setName('oro:navigation:menu:reset')
            ->addOption(
                'user',
                'u',
                InputArgument::OPTIONAL,
                'Email of existing user'
            )
            ->addOption(
                'menu',
                'm',
                InputArgument::OPTIONAL,
                'Menu name to reset'
            )
            ->setDescription('Resets menu updates depends on scope (organization/user).')
            ->setHelp('If “user” param is not set - reset global scope, otherwise reset user scope.');
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $menu = $input->getOption('menu');
        $user = null;

        $email = $input->getOption('user');
        if ($email) {
            /** @var User $user */
            $user = $this
                ->getContainer()
                ->get('oro_user.manager')
                ->findUserByEmail($email);

            if (is_null($user)) {
                throw new \Exception(sprintf('User with email %s not exists.', $email));
            }
        }

        if (!$user && !$menu) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>WARNING! Menu for GLOBAL scope will be reset. Continue (y/n)?</question>',
                true
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<error>Command aborted</error>');

                return;
            }
        }

        $this->resetMenuUpdates($user, $menu);

        if ($user) {
            if ($menu) {
                $message = sprintf('The menu "%s" for user "%s" is successfully reset.', $menu, $email);
            } else {
                $message = sprintf('All menus for user "%s" is successfully reset.', $email);
            }
        } else {
            if ($menu) {
                $message = sprintf('The menu "%s" for global scope is successfully reset.', $menu);
            } else {
                $message = sprintf('All menus in global scope is successfully reset.');
            }
        }

        $output->writeln($message);
    }

    /**=
     * @param User|null $user
     * @param string|null $menuName
     */
    private function resetMenuUpdates($user = null, $menuName = null)
    {
        $ownerId = null;
        if (null !== $user) {
            $ownershipType = $this
                ->getContainer()
                ->get('oro_navigation.ownership_provider.user')
                ->getType();

            $ownerId = $user->getId();
        } else {
            $ownershipType = $this
                ->getContainer()
                ->get('oro_navigation.ownership_provider.global')
                ->getType();
        }

        $this
            ->getContainer()
            ->get('oro_navigation.manager.menu_update_default')
            ->resetMenuUpdatesWithOwnershipType($ownershipType, $ownerId, $menuName);
    }
}
