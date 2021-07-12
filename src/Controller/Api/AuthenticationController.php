<?php


namespace App\Controller\Api;


use App\Entity\Consultant;
use Doctrine\ORM\EntityManagerInterface;
use Jose\Bundle\JoseFramework\Services\JWSBuilderFactory;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Jose\Component\Core\JWK;

#[AsController]
#[Route('/auth', name: 'auth_')]
class AuthenticationController
{
    #[Route(name:'code', methods:['POST'])]
    public function authenticateByCode(Request $request, EntityManagerInterface $em, JWK $apiJwk, JWSBuilderFactory $jwsBuilderFactory, CompactSerializer $serializer): JsonResponse
    {
        if (!$code = $request->request->get('code'))
            throw new AccessDeniedHttpException();

        $consultant = $em->getRepository(Consultant::class)->findOneBy(['authCode' => $code]);
        if (!$consultant)
            throw new AccessDeniedHttpException();

        $claims = [
            'jti' => uniqid('conagrivet', true),
            'iat' => time(),
            'exp' => time() + 3600*24*7,
            'iss' => 'conagrivet.it',
            'aud' => 'gestionale.conagrivet.it',
            'sub' => $consultant->getUserIdentifier()
        ];
        $builder = $jwsBuilderFactory->create([$apiJwk->get('alg')]);

        $jws = $builder
            ->create()
            ->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))
            ->addSignature($apiJwk, ['alg' => $apiJwk->get('alg')])
            ->build();

        $token = $serializer->serialize($jws);

        return new JsonResponse([
            'bearer' => $token
        ]);
    }
}
