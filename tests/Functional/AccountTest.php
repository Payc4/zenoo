<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;

class AccountTest extends AppTestCase
{
    public function testRegisterCreatesAccountLogsInAndSendsWelcome(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => 'Maria',
            'registration_form[email]' => '  Maria@Example.COM ',
            'registration_form[plainPassword][first]' => 'Password123',
            'registration_form[plainPassword][second]' => 'Password123',
            'registration_form[agreeTerms]' => '1',
        ]));

        $this->assertResponseRedirects('/dashboard');
        $this->assertEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'Subject', 'Welcome to ZENOO');
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com');
        $this->assertNotNull($user, 'email is stored trimmed and lower-cased');
        $this->assertNotSame('Password123', $user->getPassword(), 'password is hashed');

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.dash-head .kicker', 'Hi, Maria');
        $this->assertSelectorTextContains('.dash-empty', 'Take the free 3-minute Bowl Score quiz');
    }

    public function testRegisterValidation(): void
    {
        $this->createUser('taken@example.com');
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => '',
            'registration_form[email]' => 'TAKEN@example.com',
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $text = $this->client->getCrawler()->filter('form')->text();
        $this->assertStringContainsString('Please enter your first name.', $text);
        $this->assertStringContainsString('An account with this email already exists.', $text);
        $this->assertStringContainsString('at least 8 characters', $text);
        $this->assertStringContainsString('agree to the privacy policy', $text);
        $this->assertSame(1, static::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testLoginAndLogout(): void
    {
        $this->createUser();
        $this->loginViaForm('MARIA@example.com', 'Password123');
        $this->assertResponseRedirects('/dashboard');
        $this->client->request('GET', '/account');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/account');
        $this->assertResponseRedirects('/login');
    }

    public function testLoginIsLockedAfterFiveWrongPasswords(): void
    {
        $this->createUser();
        for ($i = 0; $i < 5; $i++) {
            $this->loginViaForm('maria@example.com', 'wrong-' . $i);
            $this->assertResponseRedirects('/login');
        }
        // Even the right password is refused while locked.
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.form-errors', 'Too many attempts');
    }

    public function testNextParameterOnlyAcceptsLocalPaths(): void
    {
        $this->createUser();
        $this->client->request('GET', '/login?next=//evil.example.com');
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->assertResponseRedirects('/dashboard');

        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/login?next=/shop/test-product');
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->assertResponseRedirects('/shop/test-product');
    }

    public function testEmailLoginLinkWorksOnceAndDoesNotRevealAccounts(): void
    {
        $this->createUser();

        $crawler = $this->client->request('GET', '/login?tab=link');
        $this->client->submit($crawler->filter('#panel-link form')->form(['email' => 'nobody@example.com']));
        $this->client->followRedirect();
        $unknown = $this->flashes();
        $this->assertEmailCount(0);

        $crawler = $this->client->request('GET', '/login?tab=link');
        $this->client->submit($crawler->filter('#panel-link form')->form(['email' => 'maria@example.com']));
        $this->assertEmailCount(1);
        $html = $this->getMailerMessage()->getHtmlBody();
        $this->client->followRedirect();
        $this->assertSame($unknown, $this->flashes(), 'same message for real and unknown emails');

        preg_match('#href="(http[^"]+/login/check\?[^"]+)"#', $html, $m);
        $url = html_entity_decode($m[1]);
        $this->client->request('GET', $url);
        $this->assertResponseRedirects('/dashboard');

        $this->client->request('GET', '/logout');
        $this->client->request('GET', $url);
        $this->assertResponseRedirects('/login', null, 'a used link does not work again');
    }

    public function testPasswordResetFlow(): void
    {
        $this->createUser();
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->client->submit($crawler->selectButton('Send reset link')->form(['email' => 'maria@example.com']));
        $this->assertEmailCount(1);

        preg_match('#/reset-password/([a-f0-9]{64})#', $this->getMailerMessage()->getHtmlBody(), $m);
        $token = $m[1];
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com');
        $this->assertSame(hash('sha256', $token), $user->getResetTokenHash(), 'only a hash of the token is stored');

        $crawler = $this->client->request('GET', '/reset-password/' . $token);
        $this->client->submit($crawler->selectButton('Save new password')->form(['password' => 'NewPassword456', 'password_confirm' => 'nope']));
        $this->assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/reset-password/' . $token);
        $this->client->submit($crawler->selectButton('Save new password')->form(['password' => 'NewPassword456', 'password_confirm' => 'NewPassword456']));
        $this->assertResponseRedirects('/login');

        $this->client->request('GET', '/reset-password/' . $token);
        $this->assertResponseRedirects('/forgot-password', null, 'token is single-use');

        $this->loginViaForm('maria@example.com', 'NewPassword456');
        $this->assertResponseRedirects('/dashboard');
    }

    public function testExpiredResetTokenIsRefused(): void
    {
        $user = $this->createUser();
        $user->setResetToken(str_repeat('ab', 32), new \DateTimeImmutable('-1 minute'));
        $this->em->flush();
        $this->client->request('GET', '/reset-password/' . str_repeat('ab', 32));
        $this->assertResponseRedirects('/forgot-password');
    }

    public function testChangeNameAndPassword(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/account');

        $this->client->submit($crawler->filter('form[action="/account/details"]')->form(['firstName' => 'Marija', 'lastName' => 'Petraitė']));
        $this->assertResponseRedirects('/account#details');

        $crawler = $this->client->request('GET', '/account');
        $this->assertSelectorTextContains('h1', 'Hi, Marija');

        $form = $crawler->filter('form[action="/account/password"]')->form(['currentPassword' => 'wrong', 'newPassword' => 'NewPassword456', 'confirmPassword' => 'NewPassword456']);
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertContains('Your current password isn’t right.', $this->flashes());

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->filter('form[action="/account/password"]')->form(['currentPassword' => 'Password123', 'newPassword' => 'NewPassword456', 'confirmPassword' => 'NewPassword456']));
        $this->assertEmailCount(1);
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful('still logged in after changing the password');
    }

    public function testAccountFormsNeedCsrfToken(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('POST', '/account/details', ['firstName' => 'Hacked']);
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com');
        $this->assertSame('Maria', $user->getFirstName());
    }

    public function testPhotoUploadReencodesImagesAndRejectsOthers(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/account');
        $form = $crawler->filter('form[action="/account/photo"]')->form();

        $fake = tempnam(sys_get_temp_dir(), 'av') . '.jpg';
        file_put_contents($fake, '<?php echo "x"; ?>');
        $form['photo']->upload($fake);
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertContains('Please choose a JPG, PNG, WebP or GIF image.', $this->flashes());

        $form = $this->client->getCrawler()->filter('form[action="/account/photo"]')->form();
        $form['photo']->upload(dirname(__DIR__, 2) . '/public/images/stage-adult.jpg');
        $this->client->submit($form);
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com');
        $this->assertMatchesRegularExpression('/^avatar_\d+_[a-f0-9]{12}\.jpg$/', (string) $user->getProfileImage());
        $path = dirname(__DIR__, 2) . '/public/uploads/profiles/' . $user->getProfileImage();
        $this->assertSame([400, 400], array_slice(getimagesize($path), 0, 2));
        unlink($path);
    }

    public function testDeleteAccountNeedsPassword(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form[action="/account/delete"]')->form(['password' => 'wrong']));
        $this->assertNotNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com'));

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form[action="/account/delete"]')->form(['password' => 'Password123']));
        $this->assertResponseRedirects('/');
        $this->em->clear();
        $this->assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com'));
        $this->client->request('GET', '/account');
        $this->assertResponseRedirects('/login');
    }

    public function testAccessControl(): void
    {
        $this->createUser();
        $this->createUser('admin@example.com', 'AdminPass123', ['ROLE_ADMIN']);
        foreach (['/account', '/admin', '/account/orders/1'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseRedirects('/login', null, $path . ' needs login');
        }

        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403, 'pet parents cannot open the admin');

        $this->client->request('GET', '/logout');
        $this->loginViaForm('admin@example.com', 'AdminPass123');
        $this->assertResponseRedirects('/admin');
        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
    }
}
