<?php

namespace App\Form\Extension;

use Psr\Log\LoggerInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Turnstile captcha for any form built with 'turnstile_enabled' => true.
 * Switched off when TURNSTILE_SECRET_KEY is empty, and in the dev and test environments.
 */
class TurnstileExtension extends AbstractTypeExtension
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $siteKey,
        private readonly ?string $secretKey,
        private readonly string $environment,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function isActive(): bool
    {
        return (string) $this->siteKey !== '' && (string) $this->secretKey !== '' && !in_array($this->environment, ['dev', 'test'], true);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['turnstile_enabled'] && $this->isActive()) {
            $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validate(...));
        }
    }

    public function validate(FormEvent $event): void
    {
        $form = $event->getForm();
        $request = $this->requestStack->getCurrentRequest();
        if (!$form->isRoot() || !$request) {
            return;
        }

        $token = (string) $request->request->get('cf-turnstile-response', '');
        if ($token === '') {
            $form->addError(new FormError('Please confirm you are human.'));

            return;
        }

        try {
            $result = $this->httpClient->request('POST', self::VERIFY_URL, [
                'timeout' => 10,
                'body' => ['secret' => $this->secretKey, 'response' => $token, 'remoteip' => $request->getClientIp()],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Turnstile verification request failed', ['error' => $e->getMessage()]);
            $form->addError(new FormError('We could not check the captcha just now. Please try again.'));

            return;
        }

        if (empty($result['success'])) {
            $this->logger->info('Turnstile rejected a submission', ['errors' => $result['error-codes'] ?? []]);
            $form->addError(new FormError('The captcha check failed. Please try again.'));
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['turnstile_site_key'] = $options['turnstile_enabled'] && $this->isActive() ? $this->siteKey : null;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['turnstile_enabled' => false]);
        $resolver->setAllowedTypes('turnstile_enabled', 'bool');
    }
}
