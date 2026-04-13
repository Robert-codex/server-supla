<?php
namespace SuplaBundle\Security;

use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

class AdminPanelUserProvider implements UserProviderInterface {
    public function __construct(private AdminPanelAccountStore $store) {
    }

    public function loadUserByUsername($username) {
        $account = $this->store->getAccount((string)$username);
        $expectedUser = (string)($account['username'] ?? '');
        $passwordHash = (string)($account['passwordHash'] ?? '');
        if ($expectedUser === '' || $passwordHash === '') {
            throw new UsernameNotFoundException('Admin panel is not configured.');
        }
        if (!(bool)($account['active'] ?? true)) {
            throw new DisabledException('Admin account is disabled.');
        }
        return new AdminPanelUser($expectedUser, $passwordHash, $this->store->getSecurityRolesForAdmin($account), true);
    }

    public function refreshUser(UserInterface $user) {
        if (!$user instanceof AdminPanelUser) {
            throw new UnsupportedUserException('Unsupported user type.');
        }
        return $this->loadUserByUsername($user->getUsername());
    }

    public function supportsClass($class) {
        return $class === AdminPanelUser::class || is_subclass_of($class, AdminPanelUser::class);
    }
}
