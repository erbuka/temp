<?php


namespace App\Security;


use Jose\Bundle\JoseFramework\Services\ClaimCheckerManager;
use Jose\Bundle\JoseFramework\Services\ClaimCheckerManagerFactory;
use Jose\Bundle\JoseFramework\Services\JWSLoaderFactory;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\AudienceChecker;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\JWSLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Jose\Bundle\JoseFramework\Services\HeaderCheckerManagerFactory;

/**
 * See https://symfony.com/doc/current/security/experimental_authenticators.html
 */
class JWSAuthenticator extends AbstractAuthenticator
{
    private JWSLoader $jwsLoader;
    protected JWK $key;
    private ClaimCheckerManager $claimCheckerManager;
//    private EntityManagerInterface $entityManager;

    public function __construct(JWK $apiJwk, JWSLoaderFactory $jwsLoader, HeaderCheckerManagerFactory $headerCheckerManagerFactory, ClaimCheckerManagerFactory $claimCheckerManagerFactory)
    {
//        $this->entityManager = $entityManager;

        assert($apiJwk->has('alg'), 'JWK must specify the preferred algorithm using the \'alg\' header');
        $this->key = $apiJwk;

        // Configure JWSLoader
        $headerCheckerManagerFactory->add('alg', new AlgorithmChecker([$apiJwk->get('alg')], true));
        $this->jwsLoader = $jwsLoader->create(['jws_compact'], [$apiJwk->get('alg')], ['alg']);

        // Configure ClaimCheckerManager
        $claimCheckerManagerFactory->add('expiration', new ExpirationTimeChecker(0, true));
        $claimCheckerManagerFactory->add('audience', new AudienceChecker('gestionale.conagrivet.it'));
        $claimCheckerManagerFactory->add('issuer', new IssuerChecker(['conagrivet.it']));
        $this->claimCheckerManager = $claimCheckerManagerFactory->create(['expiration', 'audience', 'issuer']);
    }

    public function supports(Request $request): ?bool
    {
        if (!$this->hasBearer($request))
            return false;

        try {
            $this->jwsLoader->getSerializerManager()->unserialize($this->getBearer($request));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a passport for the current request.
     *
     * The passport contains the user, credentials and any additional information
     * that has to be checked by the Symfony Security system. For example, a login
     * form authenticator will probably return a passport containing the user, the
     * presented password and the CSRF token value.
     *
     * You may throw any AuthenticationException in this method in case of error (e.g.
     * a UsernameNotFoundException when the user cannot be found).
     *
     * @throws AuthenticationException
     */
    public function authenticate(Request $request): PassportInterface
    {
        assert($this->hasBearer($request), 'Bearer validity should have been checked in '. static::class .'::supports()');

        try {
            $signature = 0;
            $jws = $this->jwsLoader->loadAndVerifyWithKey($this->getBearer($request), $this->key, $signature);
            $payload = $jws->getPayload();

            if (empty($payload))
                throw new AuthenticationCredentialsNotFoundException();

            $claims = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BadCredentialsException($e->getMessage());
        }

        try {
            $this->claimCheckerManager->check($claims, ['exp']);
        } catch (\Throwable $e) {
            throw new BadCredentialsException("Invalid claims: ". $e->getMessage());
        }

        if (empty($claims['sub']))
            throw new BadCredentialsException("Anonymous users not allowed");

        return new SelfValidatingPassport(new UserBadge($claims['sub']));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // let the request continue
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    protected function hasBearer(Request $request): bool
    {
        $auth = $request->headers->get('authorization');

        if ($auth && !strcasecmp('Bearer ', substr($auth, 0, 7)))
            return true;

        return false;
    }

    protected function getBearer(Request $request): string
    {
        $auth = $request->headers->get('authorization', '');

        return substr($auth, 7);
    }
}
