<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\StripeCheckoutSessionHandler;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly StripeCheckoutSessionHandler $sessionHandler,
    ) {
    }

    #[Route('/api/stripe/webhook', name: 'api_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
        } catch (\UnexpectedValueException $e) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        } catch (SignatureVerificationException $e) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        if ($event->type !== Event::CHECKOUT_SESSION_COMPLETED) {
            return new Response('Unhandled event type', Response::HTTP_OK);
        }

        $session = $event->data->object;

        try {
            $this->sessionHandler->handleCompletedSession($session);
        } catch (\InvalidArgumentException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new Response('OK', Response::HTTP_OK);
    }
}
