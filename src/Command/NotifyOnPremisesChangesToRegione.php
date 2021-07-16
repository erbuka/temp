<?php


namespace App\Command;


use App\ConsultantScheduleGenerator;
use App\Entity\AddTaskCommand;
use App\Entity\Consultant;
use App\Entity\MoveTaskCommand;
use App\Entity\Recipient;
use App\Entity\RemoveTaskCommand;
use App\Entity\ScheduleChangeset;
use App\Entity\ScheduleCommand;
use App\Entity\Service;
use App\Entity\Schedule;
use App\Entity\Task;
use App\Entity\TaskCommand;
use App\ScheduleManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notifier;
use Symfony\Component\Notifier\Recipient\NoRecipient;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Notifier\NotifierInterface;

#[AsCommand(
    name: 'app:notify',
)]
class NotifyOnPremisesChangesToRegione extends Command
{
    private ChatterInterface $chatter;
    private EntityManagerInterface $entityManager;
    private MailerInterface $mailer;

    public function __construct(EntityManagerInterface $entityManager, ChatterInterface $chatter, MailerInterface $mailer)
    {
        $this->entityManager = $entityManager;
        $this->chatter = $chatter;
        $this->mailer = $mailer;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $message = '';

        foreach ($this->entityManager->getRepository(Consultant::class)->findAll() as $consultant) {
            $schedule = $this->entityManager->getRepository(Schedule::class)->findOneBy(['consultant' => $consultant]);

            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('sc')
                ->from(ScheduleChangeset::class, 'sc')
                ->where('sc.schedule = :schedule')
                ->andWhere('sc.createdAt >= :start AND sc.createdAt < :end')
                ->orderBy('sc.createdAt', 'ASC');

            $qb->setParameter('start', (new \DateTime)->modify('00:00'));
            $qb->setParameter('end', (new \DateTime)->modify('+1day 00:00'));
            $qb->setParameter('schedule', $schedule);

            // Today's changesets
            $changeSets = $qb->getQuery()->getResult();

            $byRecipient = new \SplObjectStorage();
            foreach ($changeSets as $cs) {
                /** @var ScheduleChangeset $cs */
                foreach ($cs->getCommands() as $cmd) {
                    assert($cmd instanceof TaskCommand);
                    $recipient = $cmd->getTask()->getRecipient();

                    if (!$byRecipient->contains($recipient))
                        $byRecipient[$recipient] = [];

                    $byRecipient[$recipient] = [...$byRecipient[$recipient], $cmd];
                }
            }

            foreach ($byRecipient as $recipient) {
                /** @var Recipient $recipient */
                $output->writeln("<info>{$consultant->getName()}/ {$recipient->getName()}</info>");
                $message .= "\n\n{$consultant->getName()} presso {$recipient->getName()}";

                $commands = $byRecipient[$recipient];
                $removed = array_filter($commands, fn($cmd) => $cmd instanceof RemoveTaskCommand);
                $moved = array_filter($commands, fn($cmd) => $cmd instanceof MoveTaskCommand);
                $added = array_filter($commands, fn($cmd) => $cmd instanceof AddTaskCommand);

                foreach ($removed as $c) {
                    /** @var TaskCommand $c */
                    $date = $c->getTask()->getStart()->format('Y/m/d');
                    $start = $c->getTask()->getStart()->format('H:i');
                    $end = $c->getTask()->getEnd()->format('H:i');

                    $message .= "\nLa consulenza prevista in data $date alla ora $start - $end è stata cancellata e le ore previste verranno redistribuite fra le seguenti.";
                    $output->writeln("La consulenza prevista in data $date alla ora $start - $end è stata cancellata e le ore previste verranno redistribuite fra le seguenti.");
                }

                foreach ($moved as $c) {
                    /** @var MoveTaskCommand $c */
                    $date = $c->getTask()->getStart()->format('Y/m/d');
                    $start = $c->getTask()->getStart()->format('H:i');
                    $end = $c->getTask()->getEnd()->format('H:i');
                    $oldStart = $c->getPreviousStart()->format('H:i');
                    $oldEnd = $c->getPreviousEnd()->format('H:i');
                    $oldDate = $c->getPreviousStart()->format('Y/m/d');

                    $message .= "\nLa consulenza prevista in data $oldDate alle ore $oldStart - $oldEnd verrà invece eseguita il $date alle ore $start - $end.";
                    $output->writeln("La consulenza prevista in data $oldDate alle ore $oldStart - $oldEnd verrà invece eseguita il $date alle ore $start - $end.");
                }

                foreach ($added as $c) {
                    /** @var AddTaskCommand $c */
                    $date = $c->getTask()->getStart()->format('Y/m/d');
                    $start = $c->getTask()->getStart()->format('H:i');
                    $end = $c->getTask()->getEnd()->format('H:i');

                    $message .= "\nUtilizzando le ore rimanenti dalle precedenti modifiche, viene programmata una consulenza in data $date alle ore $start - $end";
                    $output->writeln("Utilizzando le ore rimanenti dalle precedenti modifiche, viene programmata una consulenza in data $date alle ore $start - $end");
                }
            }
        }


        $email = (new Email)
            ->to('ete.dne@gmail.com')
            ->subject('Aggiornamento programmazione ore in presenza')
            ->text($message);

        $this->mailer->send($email);

        return Command::SUCCESS;
    }
}
