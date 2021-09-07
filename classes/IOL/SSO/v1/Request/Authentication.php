<?php

declare(strict_types=1);

namespace IOL\SSO\v1\Request;

use IOL\SSO\v1\DataSource\File;
use IOL\SSO\v1\DataType\UUID;
use IOL\SSO\v1\Entity\User;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Nowakowskir\JWT\JWT;
use Nowakowskir\JWT\TokenDecoded;
use Nowakowskir\JWT\TokenEncoded;

class Authentication
{
    public const JWT_SESSION_KEY = 'ses';
    private const JWT_PUBLIC_KEY = '/public.pub';
    private const JWT_PRIVATE_KEY = '/private.key';
    private const JWT_ALGORITHM = JWT::ALGORITHM_RS256;

    private static User $user;
    private static Session $session;
    private static bool $authResult;

    #[ArrayShape([
        'success' => 'bool',
        'object' => 'User|Error',
    ])]
    public static function authenticate(): User
    {
        $session = self::getSessionFromRequest();

        if (!$session->isExpired()) {
            // The session is valid and still in time.
            // renew the session for further usage
            $session->renew();
            $user = $session->getUser();
            if (is_null($user)) {
                self::$authResult = false;

                APIResponse::getInstance()->addError(100002)->render();
            }
            self::$user = $user;
            self::$authResult = true;

            return $user;
        }
        // The provided session is expired (leeway is considered)
        self::$authResult = false;

        APIResponse::getInstance()->addError(100001)->render();
    }

    public static function getSessionFromRequest(): Session
    {
        // check, if Authorization header is present
        $authToken = false;
        $authHeader = APIResponse::getRequestHeader('Authorization');
        //var_dump($authHeader);
        if (!is_null($authHeader)) {
            if (str_starts_with($authHeader, 'Bearer ')) {
                $authToken = substr($authHeader, 7);
            }
        }
        if (!$authToken) {
            APIResponse::getInstance()->addError(100003)->render();
        }

        // check if given Auth header is a valid JWT token
        $authToken = new TokenEncoded($authToken);
        try {
            $authToken->validate(file_get_contents(File::getBasePath() . self::JWT_PUBLIC_KEY), self::JWT_ALGORITHM);
        } catch (Exception) { // TODO: sometimes InvalidStructureExceptions don't get caught, check why
            // Token validation failed.
            APIResponse::getInstance()->addError(100002)->render();
        } /*
                // we're not expiring tokens, handling session expiry separately

                catch (TokenExpiredException $e){
                // token is expired
                return ['success' => false,'object' => new Error(100001)];
            }*/

        // get payload from token and check, if token is still valid
        $payload = $authToken->decode()->getPayload();

        if (isset($payload[self::JWT_SESSION_KEY])) {
            $session_id = $payload[self::JWT_SESSION_KEY];
            if (UUID::isValid($session_id)) {
                $session = new Session(sessionId: $session_id);

                if ($session->sessionExists()) {
                    self::$session = $session;

                    return $session;
                }
                // The provided session id is not stored in DB, therefore is not valid
                APIResponse::getInstance()->addError(100002)->render();
            }
            // the provided session id is not a valid UUID
            APIResponse::getInstance()->addError(100002)->render();
        }
        // no session key is found in payload
        APIResponse::getInstance()->addError(100002)->render();
    }

    #[Pure] public static function getSessionId(): ?string
    {
        if (isset(self::$session)) {
            return self::$session->getSessionId();
        }
        return '';
    }

    public static function createNewToken(array $data): string
    {
        $rawToken = new TokenDecoded($data);
        $encodedToken = $rawToken->encode(
            file_get_contents(File::getBasePath() . self::JWT_PRIVATE_KEY),
            self::JWT_ALGORITHM
        );

        return $encodedToken->toString();
    }

    public static function getCurrentUser(): ?User
    {
        if (!isset(self::$authResult)) {
            self::authenticate();
        }

        return self::$authResult ? self::$user : null;
    }

    public static function isAuthenticated(): bool
    {
        return self::$authResult;
    }
}
