<?php

declare(strict_types=1);

namespace GrandGala\API\Spaces;

use GrandGala\Models\SpaceRate;
use GrandGala\Services\RateCard;
use GrandGala\Services\Rates;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class RatesController
{
    // POST /api/staff/spaces/:id/rates { basis, label, price, validFrom, validTo? }
    // Omit validTo (or null) for a normal price.
    public function add(Request $request): void
    {
        $price = $request->input('price');
        if (!is_int($price) && !is_float($price)) {
            throw new HttpError(422, 'The price must be a number');
        }

        $validTo = null;
        if ($request->input('validTo') !== null) {
            $validTo = $request->inputText('validTo');
        }

        $rate = Rates::add(
            (int) $request->param('id'),
            $request->inputText('basis'),
            $request->inputText('label'),
            (float) $price,
            $request->inputText('validFrom'),
            $validTo,
        );

        Response::json($rate, 201);
    }

    // POST /api/staff/rates/:id/cancel. Answers with the cancelled price.
    public function cancel(Request $request): void
    {
        $rateId = (int) $request->param('id');
        Rates::cancel($rateId);

        Response::json(SpaceRate::find($rateId));
    }

    // POST /api/staff/rates/:id/end-now. Answers with the ended price.
    public function endNow(Request $request): void
    {
        $rateId = (int) $request->param('id');
        Rates::endNow($rateId);

        Response::json(SpaceRate::find($rateId));
    }

    // GET /api/staff/spaces/:id/rates/overlaps?basis=&from=&to=
    public function overlaps(Request $request): void
    {
        $seasons = Rates::overlaps(
            (int) $request->param('id'),
            $request->queryText('basis'),
            $request->queryText('from'),
            $request->queryText('to'),
        );

        Response::json($seasons);
    }

    // GET /api/staff/rate-card: today's prices for the quotation builder
    public function rateCard(Request $request): void
    {
        Response::json(RateCard::items());
    }
}
