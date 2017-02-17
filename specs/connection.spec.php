<?php
/**
 * Created by PhpStorm.
 * User: vince
 * Date: 17/02/2017
 * Time: 19:17
 */
describe('connection', function() {
    context('Connect', function() {

    });
    context('Run', function() {
        context("query", function() {
            it("Pack query");
            it('Write to socket');
            it('Wait to complete');
            it('Reads headers from socket');
            it('Reads data from socket');
        });

        it("Sends query");
    });
});
->map(function ($responseBuf) use ($noChecks, $responseToken, $token, $query) {
    $response = json_decode($responseBuf);
    if (json_last_error() != JSON_ERROR_NONE) {
        throw new RqlDriverError("Unable to decode JSON response (error code " . json_last_error() . ")");
    }
    if (!is_object($response)) {
        throw new RqlDriverError("Invalid response from server: Not an object.");
    }
    $response = (array)$response;
    if (!$noChecks) {
        $this->checkResponse($response, $responseToken, $token, $query);
    }
    return $response;
