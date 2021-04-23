<?php


namespace App\Command;


use App\Entity\Service;
use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use JetBrains\PhpStorm\Deprecated;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Strategies:
 *  1 - enforce simple constraints at the database level via indexes
 *  2 - enforce constraints via Doctrine lifecycle events @PrePersist @PreUpdate @PreRemove
 *  3 - Use Symfony's Validator to programmatically validate entities at the object level (via custom Constraints)
 */
class ValidateEntities extends Command
{
    protected static $defaultName = 'app:validate-entities';

    protected EntityManagerInterface $entityManager;
    protected ValidatorInterface $validator;
    protected OutputInterface $output;
    protected InputInterface $input;

    public function __construct(EntityManagerInterface $entityManager, ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Validates the current model');
//        $this->addOption('delete', null,InputOption::VALUE_OPTIONAL, "Deletes entities not present in the raw database", false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        return Command::SUCCESS;
    }

    protected function checkUniqueTaxId()
    {

    }
}
