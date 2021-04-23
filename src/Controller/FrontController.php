<?php


namespace App\Controller;

use App\Entity\Recipient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Annotation\Route;

class FrontController extends AbstractController
{
    const APP_DIRECTORY = 'front';

    #[Route('/contracts', name: 'contracts')]
    public function generateContracts(?Profiler $profiler): Response
    {
    }

    #[Route('/contracts/{taxId}', name: 'contracts_recipient')]
    public function generateContract(string $taxId): Response
    {
        $r = $this->getDoctrine()->getRepository(Recipient::class)->findOneByTaxId($taxId);

        if (!$r)
            $this->createNotFoundException("No recipient found for tax id {$taxId}");

        return $this->render('contract.html.twig', [
            'base' => static::APP_DIRECTORY,
            'recipient' => $r
        ]);
    }

    #[Route('/contracts-list', name: 'contracts_list')]
    public function contractsList(): Response
    {
        $recipients = $this->getDoctrine()->getRepository(Recipient::class)->findAll();

        return $this->render('contracts-list.html.twig', [
            'recipients' => $recipients
        ]);
    }

    #[Route('/{req<(?!api\/).*>}', name: 'app')]
    public function index(): Response
    {
        $scripts = Finder::create()
            ->in($this->getParameter('kernel.project_dir') ."/public/".static::APP_DIRECTORY)
            ->depth('== 0')
            ->files()
            ->name('*.js');

        $styles = Finder::create()
            ->in($this->getParameter('kernel.project_dir') ."/public/".static::APP_DIRECTORY."/assets")
            ->files()
            ->name('*.css');

        return $this->render('app.html.twig', [
            'scripts' => $scripts,
            'styles' => $styles,
            'base' => static::APP_DIRECTORY
        ]);
    }
}
