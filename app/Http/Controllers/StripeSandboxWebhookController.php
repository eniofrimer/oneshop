<?php

namespace App\Http\Controllers;

use App\Payments\PaymentEventInbox;
use App\Payments\StripeSandboxConfiguration;
use App\Payments\StripeSandboxGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;

class StripeSandboxWebhookController
{
    public function __invoke(Request $request, StripeSandboxGateway $gateway, PaymentEventInbox $inbox): JsonResponse
    {
        abort_unless(StripeSandboxConfiguration::enabled(), 404);
        abort_unless(str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json'), 415);
        $body = $request->getContent();
        abort_if(strlen($body) > 262144, 413);
        $signature = (string) $request->header('Stripe-Signature');
        abort_if(strlen($signature) > 1024, 400);
        try {
            $event = $gateway->verifyEvent($body, $signature);
        } catch (SignatureVerificationException|\UnexpectedValueException|\InvalidArgumentException|\DomainException) {
            return response()->json(['message' => 'Invalid Stripe test event.'], 400);
        } catch (\LogicException) {
            return response()->json(['message' => 'Stripe sandbox is not configured.'], 503);
        }
        if ($event !== null) {
            try {
                $inbox->record($event);
            } catch (\DomainException) {
                return response()->json(['message' => 'Conflicting event identity.'], 409);
            }
        }

        // Acknowledge only after durable storage. Scheduler performs all API
        // calls/settlement, so no browser session or queue daemon is required.
        return response()->json(['received' => true]);
    }
}
