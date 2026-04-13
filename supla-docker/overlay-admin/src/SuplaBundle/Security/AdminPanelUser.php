<?php
namespace SuplaBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class AdminPanelUser implements UserInterface {
    /**
     * @param string[] $roles
     */
    public function __construct(
        private string $username,
        private string $passwordHash,
        private array $roles = ['ROLE_ADMIN_PANEL', 'ROLE_ADMIN_SUPER'],
        private bool $active = true
    ) {
    }

    public function getRoles(): array {
        return array_values(array_unique(array_merge(['ROLE_ADMIN_PANEL'], $this->roles)));
    }

    public function getPassword(): string {
        return $this->passwordHash;
    }

    public function getSalt() {
        return null;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function isActive(): bool {
        return $this->active;
    }

    public function eraseCredentials() {
    }
}
