<?php


namespace App\Controller\Front;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FrontController extends AbstractController
{
    const APP_DIRECTORY = 'app';

    #[Route('/contracts', name: 'contracts')]
    public function generateContracts(): Response
    {
        return $this->render('contracts.html.twig', [
            'base' => static::APP_DIRECTORY
        ]);
    }

    #[Route('/{req<(?!api\/).*>}', name: 'index')]
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
