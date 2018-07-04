<?php

/*
 * autoregistration extension for Contao Open Source CMS
 *
 * @copyright  Copyright (c) 2018, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    LGPL-3.0+
 * @link       http://github.com/terminal42/contao-folderpage
 */

namespace Terminal42\AutoRegistrationBundle\EventListener;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FrontendUser;
use Contao\MemberModel;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

class RegistrationListener
{
    /**
     * @var UserProviderInterface
     */
    private $userProvider;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * RegistrationListener constructor.
     *
     * @param UserProviderInterface    $userProvider
     * @param TokenStorageInterface    $tokenStorage
     * @param Connection               $connection
     * @param LoggerInterface          $logger
     * @param EventDispatcherInterface $eventDispatcher
     * @param RequestStack             $requestStack
     */
    public function __construct(
        UserProviderInterface $userProvider,
        TokenStorageInterface $tokenStorage,
        Connection $connection,
        LoggerInterface $logger,
        EventDispatcherInterface $eventDispatcher,
        RequestStack $requestStack
    ) {
        $this->userProvider = $userProvider;
        $this->tokenStorage = $tokenStorage;
        $this->connection = $connection;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->requestStack = $requestStack;
    }

    /**
     * Within the registration process, log in the user if needed.
     *
     * @param int   $userId The user id
     * @param array $data   The user data of the registration module
     */
    public function onCreateNewUser(int $userId, array $data): void
    {
        global $objPage;

        $pageModel = PageModel::findById($objPage->rootId);

        if (null === $pageModel) {
            return;
        }

        if ($pageModel->auto_activate_registration) {
            $match = $this->connection->createQueryBuilder()
                ->update('tl_member')
                ->set('disable', '')
                ->where('id=:id')
                ->setParameter('id', $userId)
                ->execute()
            ;

            if ($pageModel->auto_login_registration && $match) {
                $this->loginUser($data['username']);
            }
        }
    }

    /**
     * Within the activation process, log in the user if needed.
     *
     * @param MemberModel $member
     */
    public function onActivateAccount(MemberModel $member): void
    {
        global $objPage;

        $pageModel = PageModel::findById($objPage->rootId);

        if (null === $pageModel) {
            return;
        }

        if ($pageModel->auto_login_activation) {
            $this->loginUser($member->username);
        }
    }

    /**
     * Actually log in the user by given username.
     *
     * @param string $username
     */
    private function loginUser(string $username): void
    {
        try {
            $user = $this->userProvider->loadUserByUsername($username);
        } catch (UsernameNotFoundException $exception) {
            return;
        }

        if (!$user instanceof FrontendUser) {
            return;
        }

        $usernamePasswordToken = new UsernamePasswordToken($user, null, 'frontend', $user->getRoles());
        $this->tokenStorage->setToken($usernamePasswordToken);

        $event = new InteractiveLoginEvent($this->requestStack->getCurrentRequest(), $usernamePasswordToken);
        $this->eventDispatcher->dispatch('security.interactive_login', $event);

        $this->logger->log(
            LogLevel::INFO,
            'User "'.$username.'" was logged in automatically',
            ['contao' => new ContaoContext(__METHOD__, TL_ACCESS)]
        );
    }
}
