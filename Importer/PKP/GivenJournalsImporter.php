<?php

namespace Vipa\ImportBundle\Importer\PKP;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Exception;
use Vipa\ImportBundle\Importer\Importer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GivenJournalsImporter extends Importer
{
    /** @var UserImporter */
    private $ui;

    /**
     * AllJournalsImporter constructor.
     * @param Connection $dbalConnection
     * @param EntityManager $em
     * @param LoggerInterface $logger
     * @param OutputInterface $consoleOutput
     * @param UserImporter $ui
     */
    public function __construct(
        Connection $dbalConnection,
        EntityManager $em,
        LoggerInterface $logger,
        OutputInterface $consoleOutput,
        UserImporter $ui
    )
    {
        parent::__construct($dbalConnection, $em, $logger, $consoleOutput);
        $this->ui = $ui;
    }

    /**
     * Imports specified journals
     * @param array $ids IDs of journals who are going to be imported
     * @throws \Doctrine\DBAL\DBALException
     */
    public function importJournals($ids)
    {
        $journalsSql = 'SELECT journal_id, path FROM journals WHERE journal_id IN (?)';
        $journalsStatement = $this->dbalConnection->executeQuery($journalsSql, array($ids), array(Connection::PARAM_INT_ARRAY));
        $journals = $journalsStatement->fetchAll();

        foreach ($journals as $journal) {
            $existingJournal = $this->em
                ->getRepository('VipaJournalBundle:Journal')
                ->findOneBy(['slug' => $journal['path']]);

            if (!$existingJournal) {
                try {
                    $journalImporter = new JournalImporter(
                        $this->dbalConnection, $this->em, $this->logger, $this->consoleOutput, $this->ui
                    );
                    
                    $ids = $journalImporter->importJournal($journal['journal_id']);
                    $journalUserImporter = new JournalUserImporter($this->dbalConnection, $this->em, $this->logger, $this->consoleOutput);
                    $journalUserImporter->importJournalUsers($ids['new'], $ids['old'], $this->ui);
                } catch (Exception $exception) {
                    $message = sprintf(
                        '%s: %s (uncaught exception) at %s line %s',
                        get_class($exception),
                        $exception->getMessage(),
                        $exception->getFile(),
                        $exception->getLine()
                    );

                    $this->consoleOutput->writeln('Importing of journal #' . $journal['journal_id'] . ' failed.');
                    $this->consoleOutput->writeln($message);
                    $this->consoleOutput->writeln($exception->getTraceAsString());
                }
            } else {
                $this->consoleOutput->writeln('Journal #' . $journal['journal_id'] . ' already imported. Skipped.');
            }
        }
    }

}
