<?php


namespace App\Controller\Front;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FrontController extends AbstractController
{
    #[Route('/{req<(?!api\/).*>}', name: 'index')]
    public function index(): Response
    {
        $appDirectory = 'app';

        $scripts = Finder::create()
            ->in($this->getParameter('kernel.project_dir') ."/public/{$appDirectory}")
            ->depth('== 0')
            ->files()
            ->name('*.js');

        $styles = Finder::create()
            ->in($this->getParameter('kernel.project_dir') ."/public/{$appDirectory}/assets")
            ->files()
            ->name('*.css');

        return $this->render('app.html.twig', [
            'scripts' => $scripts,
            'styles' => $styles,
            'base' => $appDirectory
        ]);
    }
}
