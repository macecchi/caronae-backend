<?php

namespace Tests;

use Caronae\Http\Middleware\ApiV1Authenticate;
use Caronae\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use JWTAuth;
use Symfony\Component\HttpFoundation\HeaderBag;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class ApiV1AuthenticateTest extends TestCase
{
    use DatabaseTransactions;
    private $middleware;

    public function setUp()
    {
        parent::setUp();
        $this->middleware = new ApiV1Authenticate();
    }

    /** @test */
    public function shouldReturn401WithInvalidToken()
    {
        $request = $this->unauthorizedLegacyRequest('xxx');

        $response = $this->middleware->handle($request, function($r){ });

        $this->assertResponseIsUnauthorized($response);
    }

    /** @test */
    public function shouldReturn401WithoutToken()
    {
        $request = $this->unauthorizedLegacyRequest(null);

        $response = $this->middleware->handle($request, function($r){ });

        $this->assertResponseIsUnauthorized($response);
    }

    /** @test */
    public function shouldContinueWithValidLegacyToken()
    {
        $request = $this->authenticatedLegacyRequest();

        $result = $this->middleware->handle($request, function() {
            return 'next';
        });

        $this->assertEquals($result, 'next');
    }

    /** @test */
    public function shouldContinueWithValidJWTToken()
    {
        $user = factory(User::class)->create()->fresh();
        $token = JWTAuth::fromUser($user);
        JWTAuth::shouldReceive('parseToken->authenticate')->andReturn($user);

        $request = new Request();
        $request->headers = new HeaderBag(['Authorization' => "Bearer $token"]);

        $result = $this->middleware->handle($request, function() {
            return 'next';
        });

        $this->assertEquals($result, 'next');
    }

    /** @test */
    public function shouldReturn401WhenUserIsBanned()
    {
        $user = factory(User::class)->create(['banned' => true])->fresh();
        JWTAuth::shouldReceive('parseToken->authenticate')->andReturn($user);

        $request = $this->jwtRequest();

        $response = $this->middleware->handle($request, function($r){ });

        $this->assertResponseIsUnauthorized($response);
    }

    /** @test */
    public function shouldReturn401WhenTokenIsExpired()
    {
        JWTAuth::shouldReceive('parseToken->authenticate')->andThrow(new TokenExpiredException());
        $request = $this->jwtRequest();

        $response = $this->middleware->handle($request, function($r){ });

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertArraySubset([ 'error' => 'Token is expired.' ], (array)$response->getData());
    }

    /** @test */
    public function shouldReturn400WhenTokenIsInvalid()
    {
        JWTAuth::shouldReceive('parseToken->authenticate')->andThrow(new TokenInvalidException());
        $request = $this->jwtRequest();

        $response = $this->middleware->handle($request, function($r){ });

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertArraySubset([ 'error' => 'Token is invalid.' ], (array)$response->getData());
    }

    /** @test */
    public function shouldReturn500WhenJWTThrowsError()
    {
        JWTAuth::shouldReceive('parseToken->authenticate')->andThrow(new JWTException());

        $request = $this->jwtRequest();

        $response = $this->middleware->handle($request, function($r){ });

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertArraySubset([ 'error' => 'Error validating token.' ], (array)$response->getData());
    }

    /** @test */
    public function shouldSetCurrentUserInRequest()
    {
        $request = $this->authenticatedLegacyRequest();

        $result = $this->middleware->handle($request, function($request) {
            return $request;
        });

        $this->assertNotNull($result->currentUser);
    }

    private function assertResponseIsUnauthorized($response, $statusCode = 401)
    {
        $this->assertEquals($statusCode, $response->getStatusCode());
        $this->assertArrayHasKey('error', (array)$response->getData());
    }

    private function authenticatedLegacyRequest()
    {
        $user = factory(User::class)->create()->fresh();
        $request = new Request();
        $request->headers = new HeaderBag(['token' => $user->token]);
        return $request;
    }

    private function unauthorizedLegacyRequest($token)
    {
        $request = new Request();
        $request->headers = new HeaderBag(['token' => $token]);
        return $request;
    }

    /**
     * @return Request
     */
    private function jwtRequest(): Request
    {
        $request = new Request();
        $request->headers = new HeaderBag(['Authorization' => "Bearer token"]);
        return $request;
    }
}
