<?php


namespace App\Controller;

use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Task;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Annotation\Route;
//use Symfony\Component\HttpKernel\Attribute\AsController;

//#[AsController]
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
        $recipient = $this->getDoctrine()->getRepository(Recipient::class)->findOneByTaxId($taxId);
        if (!$recipient)
            throw $this->createNotFoundException("No recipient found for tax id {$taxId}");

        $contract = $this->getDoctrine()->getRepository(Contract::class)->findOneBy(['recipient' => $recipient]);

        $contractedServices = $contract->getContractedServices();

        // There is no more than 1 consultant associated with 1 activity
//        $serviceConsultants = [];
//        foreach ($contractedServices as $cs) {
//            $serviceName = $cs->getService()->getName();
//            $consultant = $cs->getConsultant();
//
//            if (!isset($services[$serviceName]))
//                $services[$serviceName] = [$consultant];
//            else {
//                if (!in_array($consultant, $serviceConsultants[$serviceName]))
//                    $serviceConsultants[$serviceName][] = $consultant;
//            }
//        }

        $amount = 0;
        foreach ($contractedServices as $cs) {
            $amount += $cs->getService()->getHours() * 54;
        }

        $financedAmount = $amount * 0.2;

        return $this->render('contract.html.twig', [
            'base' => static::APP_DIRECTORY,
            'recipient' => $recipient,
            'services' => $contractedServices,
            'amount' => $amount,
            'amount_financed' => $financedAmount,
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

    #[Route('/schedules')]
    public function generateSchedules(EntityManagerInterface $entityManager, ScheduleManagerFactory $scheduleManagerFactory): Response
    {
        $schedules = $entityManager
            ->createQuery('SELECT s FROM '. Schedule::class .' s ORDER BY s.consultant ASC')
            ->getResult();

        $consultants = [];
        foreach ($schedules as $schedule) {
            /** @var Schedule $schedule */
            $manager = $scheduleManagerFactory->createScheduleManager($schedule);

            $tasks = [];
            foreach ($schedule->getTasks() as $task) {
                /** @var Task $task */
                if (!$task->isOnPremises())
                    continue;

                $tasks[] = [
                    'date' => $task->getStart()->format('Y-m-d'),
                    'start' => $task->getStart()->format('H:i'),
                    'end' => $task->getEnd()->format('H:i'),
                    'recipient' => $task->getRecipient()->getName(),
                    'location' => $task->getRecipient()->getHeadquarters(),
                ];
            }

            $consultant = $schedule->getConsultant();
            $consultants[] = [
                'name' => $consultant->getName(),
                'job' => $consultant->getJobTitle(),
                'tasks' => $tasks,
                'hours' => $manager->getConsultantHours($consultant),
                'hours_on_premises' => $manager->getConsultantHoursOnPremises($consultant),
            ];
        }

        return $this->render('schedule-all.twig', [
            'base' => static::APP_DIRECTORY,
            'consultants' => $consultants
        ]);
    }

    #[Route('/schedules-list')]
    public function generateSchedulesList(EntityManagerInterface $entityManager): Response
    {
        $schedules = $entityManager
            ->createQuery('SELECT s FROM '. Schedule::class .' s ORDER BY s.consultant ASC')
            ->getResult();

        return $this->render('schedules-list.twig', [
            'base' => static::APP_DIRECTORY,
            'schedules' => $schedules
        ]);
    }

    #[Route('/schedules/{scheduleId}')]
    public function generateSchedule(int $scheduleId, EntityManagerInterface $entityManager, ScheduleManagerFactory $scheduleManagerFactory): Response
    {
        $schedule = $entityManager->getRepository(Schedule::class)->find($scheduleId);
        if (!$schedule)
            throw $this->createNotFoundException("Schedule {$scheduleId} not found");

        $consultant = $schedule->getConsultant();
        $manager = $scheduleManagerFactory->createScheduleManager($schedule);

        $tasks = [];
        foreach ($schedule->getTasks() as $task) {
            /** @var Task $task */
            if (!$task->isOnPremises())
                continue;

            $tasks[] = [
                'date' => $task->getStart()->format('Y-m-d'),
                'start' => $task->getStart()->format('H:i'),
                'end' => $task->getEnd()->format('H:i'),
                'recipient' => $task->getRecipient()->getName(),
                'location' => $task->getRecipient()->getHeadquarters(),
            ];
        }

        return $this->render('schedule-consultant.twig', [
            'base' => static::APP_DIRECTORY,
            'consultant' => $schedule->getConsultant(),
            'tasks' => $tasks,
            'hours' => $manager->getConsultantHours($consultant),
            'hours_on_premises' => $manager->getConsultantHoursOnPremises($consultant),
        ]);
    }

    #[Route('/{req<(?!api\/).*>}', name: 'app')]
    public function index(Request $request): Response
    {
        $scripts = Finder::create()
            ->in($this->getParameter('kernel.project_dir') ."/public/".static::APP_DIRECTORY)
            ->depth('== 0')
            ->files()
            ->name('index.*.js');

//        $styles = Finder::create()
//            ->in($this->getParameter('kernel.project_dir') ."/public/".static::APP_DIRECTORY."/assets/styles")
//            ->files()
//            ->name('*.css');

        return $this->render('app.html.twig', [
            'scripts' => $scripts,
//            'styles' => $styles,
            'base' => static::APP_DIRECTORY
        ]);
    }
}
