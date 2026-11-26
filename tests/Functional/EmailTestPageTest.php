<?php

namespace App\Tests\Functional;

final class EmailTestPageTest extends AppTestCase
{
    public function testAnAdminCanSendOneOfEachEmailToThemselves(): void
    {
        $admin = $this->createUser('boss@example.com', 'Password123', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/email-test');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'boss@example.com');

        $this->client->submit($crawler->selectButton('Send all test emails to me')->form(['locale' => 'lt']));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.et-fail');
        $this->assertEmailCount(9);
        $this->assertSame(1, substr_count($this->client->getResponse()->getContent(), 'ZENOO Plan Series'));
        // the admin's own account is untouched
        $this->assertSame('boss@example.com', $admin->getEmail());
    }

    public function testMembersCannotUseIt(): void
    {
        $this->client->loginUser($this->createUser('member@example.com', 'Password123'));
        $this->client->request('GET', '/admin/email-test');
        $this->assertResponseStatusCodeSame(403);
    }
}
