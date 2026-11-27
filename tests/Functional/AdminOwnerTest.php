<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** The owner (ADMIN_EMAIL in Coolify) is the only admin who can make or remove admins. */
final class AdminOwnerTest extends AppTestCase
{
    private function owner(string $email, string $password = ''): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:admin:owner'));
        $tester->execute(['email' => $email, 'password' => $password]);

        return $tester;
    }

    private function reload(User $user): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($user->getId());
    }

    public function testTheCommandMakesTheAdminEmailAccountTheOwner(): void
    {
        // First deploy: no account yet, so it's made with ADMIN_PASSWORD.
        $this->assertSame(0, $this->owner('boss@example.com', 'Secret12345')->getStatusCode());
        $boss = $this->em->getRepository(User::class)->findOneBy(['email' => 'boss@example.com']);
        $this->assertTrue($boss->isOwner());
        $this->assertContains('ROLE_ADMIN', $boss->getRoles());
        $hash = $boss->getPassword();

        // Later deploys: still the owner, password untouched, even with another one given.
        $this->owner('boss@example.com', 'Different999');
        $this->assertSame($hash, $this->reload($boss)->getPassword());

        // ADMIN_EMAIL changed: the new account is the owner, the old one stays an admin.
        $next = $this->createUser('next@example.com');
        $this->owner('next@example.com');
        $this->assertTrue($this->reload($next)->isOwner());
        $old = $this->em->getRepository(User::class)->find($boss->getId());
        $this->assertFalse($old->isOwner());
        $this->assertTrue($old->isAdmin());

        // No account and no password: nothing is made.
        $this->assertSame(1, $this->owner('nobody@example.com')->getStatusCode());
    }

    public function testTheRoleTicksNeverChangeTheOwner(): void
    {
        $user = $this->createUser('boss@example.com');
        $user->setOwner(true);
        $user->setRoles(['ROLE_USER']); // e.g. the owner unticks Admin on their own row
        $this->assertTrue($user->isOwner());
        $this->assertTrue($user->isAdmin());

        $other = $this->createUser('other@example.com');
        $other->setRoles(['ROLE_ADMIN', User::ROLE_OWNER]);
        $this->assertFalse($other->isOwner(), 'only app:admin:owner makes an owner');
    }

    public function testOnlyTheOwnerManagesAdmins(): void
    {
        $owner = $this->createUser('boss@example.com');
        $owner->setOwner(true);
        $admin = $this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']);
        $member = $this->createUser('member@example.com');
        $this->em->flush();

        // A plain admin: manages pet parents, but can't give admin, and can't touch admins.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/user/' . $member->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[type=checkbox][value=ROLE_ADMIN]'), 'no role ticks for a plain admin');
        $this->client->request('GET', '/admin/user/' . $owner->getId() . '/edit');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/user/' . $admin->getId() . '/edit');
        $this->assertResponseStatusCodeSame(403, 'not even their own admin account (that is the owner’s call)');
        $index = $this->client->request('GET', '/admin/user');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Owner', $index->filter('body')->text());

        // The owner: can make the member an admin.
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/admin/user/' . $member->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[name=User]')->form();
        $form['User[roles]'][1]->tick();
        $this->client->submit($form);
        $this->assertTrue($this->reload($member)->isAdmin());

        // And take it away again from the other admin.
        $crawler = $this->client->request('GET', '/admin/user/' . $admin->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[name=User]')->form();
        $form['User[roles]'][1]->untick();
        $this->client->submit($form);
        $this->assertFalse($this->reload($admin)->isAdmin());
        $this->assertTrue($this->em->getRepository(User::class)->find($owner->getId())->isOwner());
    }
}
