<?php

namespace Vipa\ImportBundle\Importer\PKP;

use Behat\Transliterator\Transliterator;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Exception;
use Jb\Bundle\FileUploaderBundle\Entity\FileHistory;
use Vipa\CoreBundle\Params\PublisherStatuses;
use Vipa\ImportBundle\Entity\ImportMap;
use Vipa\JournalBundle\Entity\ContactTypes;
use Vipa\JournalBundle\Entity\Journal;
use Vipa\JournalBundle\Entity\JournalContact;
use Vipa\JournalBundle\Entity\JournalPage;
use Vipa\JournalBundle\Entity\Lang;
use Vipa\JournalBundle\Entity\Publisher;
use Vipa\JournalBundle\Entity\Subject;
use Vipa\JournalBundle\Entity\SubmissionChecklist;
use Vipa\ImportBundle\Entity\PendingDownload;
use Vipa\ImportBundle\Importer\Importer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JournalImporter extends Importer
{
    /**
     * @var Journal
     */
    private $journal;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var UserImporter
     */
    private $userImporter;

    /**
     * @var SectionImporter
     */
    private $sectionImporter;

    /**
     * @var IssueImporter
     */
    private $issueImporter;

    /**
     * @var ArticleImporter
     */
    private $articleImporter;

    /**
     * @var BoardImporter
     */
    private $boardImporter;

    /**
     * @var BoardMemberImporter
     */
    private $boardMemberImporter;

    /**
     * @var JournalPageImporter
     */
    private $journalPageImporter;

    /**
     * JournalImporter constructor.
     * @param Connection $dbalConnection
     * @param EntityManager $em
     * @param OutputInterface $consoleOutput
     * @param LoggerInterface $logger
     * @param UserImporter $ui
     */
    public function __construct(
        Connection $dbalConnection,
        EntityManager $em,
        LoggerInterface $logger,
        OutputInterface $consoleOutput,
        UserImporter $ui)
    {
        parent::__construct($dbalConnection, $em, $logger, $consoleOutput);

        $this->userImporter = $ui;
        $this->sectionImporter = new SectionImporter($this->dbalConnection, $this->em, $this->logger, $consoleOutput);
        $this->issueImporter = new IssueImporter($this->dbalConnection, $this->em, $this->logger, $consoleOutput);
        $this->articleImporter = new ArticleImporter($this->dbalConnection, $this->em, $logger, $consoleOutput, $this->userImporter);
        $this->boardImporter = new BoardImporter($this->dbalConnection, $this->em, $logger, $consoleOutput);
        $this->boardMemberImporter = new BoardMemberImporter($this->dbalConnection, $this->em, $logger, $consoleOutput, $this->userImporter);
        $this->journalPageImporter = new JournalPageImporter($this->dbalConnection, $this->em, $logger, $consoleOutput);
    }

    /**
     * Imports the journal with given ID
     * @param  int $id Journal's ID
     * @return array New IDs as keys, old IDs as values
     * @throws Exception
     * @throws \Doctrine\DBAL\DBALException
     */
    public function importJournal($id)
    {
        $this->consoleOutput->writeln("Importing the journal...");

        $journalSql = "SELECT path, primary_locale FROM journals WHERE journal_id = :id LIMIT 1";
        $journalStatement = $this->dbalConnection->prepare($journalSql);
        $journalStatement->bindValue('id', $id);
        $journalStatement->execute();

        $settingsSql = "SELECT locale, setting_name, setting_value FROM journal_settings WHERE journal_id = :id";
        $settingsStatement = $this->dbalConnection->prepare($settingsSql);
        $settingsStatement->bindValue('id', $id);
        $settingsStatement->execute();

        $pkpJournal = $journalStatement->fetch();
        $pkpSettings = $settingsStatement->fetchAll();
        $primaryLocale = $pkpJournal['primary_locale'];
        $languageCode = mb_substr($primaryLocale, 0, 2, 'UTF-8');

        !$pkpJournal && die('Journal not found.' . PHP_EOL);
        $this->consoleOutput->writeln("Reading journal settings...");

        foreach ($pkpSettings as $setting) {
            $locale = !empty($setting['locale']) ? mb_substr($setting['locale'], 0, 2, 'UTF-8') : $languageCode;
            $name = $setting['setting_name'];
            $value = $setting['setting_value'];
            $this->settings[$locale][$name] = $value;
        }

        $this->journal = new Journal();
        $this->journal->setStatus(1);
        $this->journal->setSlug($pkpJournal['path']);

        // Fill translatable fields in all available languages except the primary one
        foreach ($this->settings as $fieldLocale => $fields) {
            if ($fieldLocale === $languageCode) {
                // We will fill fields of the primary language later.
                continue;
            }

            $this->journal->setCurrentLocale(mb_substr($fieldLocale, 0, 2, 'UTF-8'));

            !empty($fields['title']) ?
                $this->journal->setTitle($fields['title']) :
                $this->journal->setTitle('Unknown Journal');

            !empty($fields['description']) ?
                $this->journal->setDescription($fields['description']) :
                $this->journal->setDescription('-');
        }

        $this->journal->setCurrentLocale($languageCode);

        // Fill fields for the primary language
        !empty($this->settings[$languageCode]['title']) ?
            $this->journal->setTitle($this->settings[$languageCode]['title']) :
            $this->journal->setTitle('Unknown Journal');

        !empty($this->settings[$languageCode]['description']) ?
            $this->journal->setDescription($this->settings[$languageCode]['description']) :
            $this->journal->setDescription('-');

        !empty($this->settings[$languageCode]['journalPageFooter']) ?
            $this->journal->setFooterText($this->settings[$languageCode]['journalPageFooter']) :
            $this->journal->setFooterText(null);

        !empty($this->settings[$languageCode]['printIssn']) && count($this->settings[$languageCode]['printIssn']) == 9 ?
            $this->journal->setIssn($this->settings[$languageCode]['printIssn']) :
            $this->journal->setIssn('');

        !empty($this->settings[$languageCode]['onlineIssn']) && count($this->settings[$languageCode]['onlineIssn']) == 9 ?
            $this->journal->setEissn($this->settings[$languageCode]['onlineIssn']) :
            $this->journal->setEissn('');

        $date = sprintf('%d-01-01 00:00:00',
            !empty($this->settings[$languageCode]['initialYear']) ?
                $this->settings[$languageCode]['initialYear'] : '2015');
        $this->journal->setFounded(DateTime::createFromFormat('Y-m-d H:i:s', $date));

        // Set view and download counts
        !empty($this->settings[$languageCode]['total_views']) ?
            $this->journal->setViewCount($this->settings[$languageCode]['total_views']) :
            $this->journal->setViewCount(0);
        !empty($this->settings[$languageCode]['total_downloads']) ?
            $this->journal->setDownloadCount($this->settings[$languageCode]['total_downloads']) :
            $this->journal->setDownloadCount(0);
        !empty($this->settings[$languageCode]['homeHeaderTitleImage']) ?
            $header = unserialize($this->settings[$languageCode]['homeHeaderTitleImage']) :
            $header = null;

        if ($header) {
            $baseDir = '/../web/uploads/journal/imported/';
            $croppedBaseDir = '/../web/uploads/journal/croped/imported/';
            $headerPath = $id . '/' . $header['uploadName'];

            $pendingDownload = new PendingDownload();
            $pendingDownload
                ->setSource('public/journals/' . $headerPath)
                ->setTarget($baseDir . $headerPath)
                ->setTag('journal-header');

            $croppedPendingDownload = new PendingDownload();
            $croppedPendingDownload
                ->setSource('public/journals/' . $headerPath)
                ->setTarget($croppedBaseDir . $headerPath)
                ->setTag('journal-header');

            $history = $this->em->getRepository(FileHistory::class)->findOneBy(['fileName' => 'imported/' . $headerPath]);

            if (!$history) {
                $history = new FileHistory();
                $history->setFileName('imported/' . $headerPath);
                $history->setOriginalName('imported/' . $headerPath);
                $history->setType('journal');
            }

            $this->em->persist($croppedPendingDownload);
            $this->em->persist($pendingDownload);
            $this->em->persist($history);
            $this->journal->setHeader('imported/' . $headerPath);
        }

        $subjects = $this->importSubjects($languageCode);

        foreach ($subjects as $subject) {
            $this->journal->addSubject($subject);
        }

        // Set publisher
        !empty($this->settings[$languageCode]['publisherInstitution']) ?
            $this->importAndSetPublisher($this->settings[$languageCode]['publisherInstitution'], $languageCode) :
            $this->journal->setPublisher($this->getUnknownPublisher($languageCode));

        // Use existing languages or create if needed
        $language = $this->em
            ->getRepository('VipaJournalBundle:Lang')
            ->findOneBy(['code' => $languageCode]);
        $this->journal->setMandatoryLang($language ? $language : $this->createLanguage($languageCode));
        $this->journal->addLanguage($language ? $language : $this->createLanguage($languageCode));

        $this->importContacts($languageCode);
        $this->importSubmissionChecklist($languageCode);

        $this->consoleOutput->writeln("Read journal's settings.");
        $this->em->beginTransaction(); // Outer transaction

        try {
            $this->em->beginTransaction(); // Inner transaction
            $this->em->persist($this->journal);
            $this->em->flush();
            $this->em->commit();
        } catch (Exception $exception) {
            $this->em->rollback();
            throw $exception;
        }

        $this->consoleOutput->writeln("Imported journal #" . $id);

        // Those below also create their own inner transactions
        $createdSections = $this->sectionImporter->importJournalSections($id, $this->journal->getId());
        $createdIssues = $this->issueImporter->importJournalIssues($id, $this->journal->getId(), $createdSections);
        $this->articleImporter->importJournalArticles($id, $this->journal->getId(), $createdIssues, $createdSections);
        $this->journalPageImporter->importPages($id, $this->journal->getId());

        $createdBoards = $this->boardImporter->importBoards($id, $this->journal->getId());

        foreach ($createdBoards as $oldBoardId => $newBoardId) {
            $this->boardMemberImporter->importBoardMembers($oldBoardId, $newBoardId);
        }

        $this->importAboutPage();

        $map = new ImportMap($id, $this->journal->getId(), Journal::class);
        $this->em->persist($map);
        $this->em->flush();
        $this->em->commit();

        return ['new' => $this->journal->getId(), 'old' => $id];
    }

    /**
     * Imports the publisher with given name and assigns it to
     * the journal. It uses the one from the database in case
     * it exists.
     * @param String $name Publisher's name
     * @param String $locale Locale of the settings
     */
    private function importAndSetPublisher($name, $locale)
    {
        $translation = $this->em
            ->getRepository('VipaJournalBundle:PublisherTranslation')
            ->findOneBy(['name' => $name]);
        $publisher = $translation !== null ? $translation->getTranslatable() : null;

        if (!$publisher) {
            $url = !empty($this->settings[$locale]['publisherUrl']) ? $this->settings[$locale]['publisherUrl'] : null;
            $publisher = $this->createPublisher($this->settings[$locale]['publisherInstitution'], $url, $locale);
            $publisher->setStatus(PublisherStatuses::STATUS_COMPLETE);

            foreach ($this->settings as $fieldLocale => $fields) {
                $publisher->setCurrentLocale(mb_substr($fieldLocale, 0, 2, 'UTF-8'));
                !empty($fields['publisherNote']) ?
                    $publisher->setAbout($fields['publisherNote']) :
                    $publisher->setAbout('-');
            }
        }

        $this->journal->setPublisher($publisher);
    }

    /**
     * Fetches the publisher with the name "Unknown Publisher".
     * @param string $locale Locale of translatable fields
     * @return Publisher Publisher with the name "Unknown Publisher"
     */
    private function getUnknownPublisher($locale)
    {
        $translation = $this->em
            ->getRepository('VipaJournalBundle:PublisherTranslation')
            ->findOneBy(['name' => 'Unknown Publisher']);
        $publisher = $translation !== null ? $translation->getTranslatable() : null;

        if (!$publisher) {
            $publisher = $this->createPublisher('Unknown Publisher', 'http://example.com', $locale);
            $publisher->setCurrentLocale(mb_substr($locale, 0, 2, 'UTF-8'))->setAbout('-');
            $this->em->persist($publisher);
        }

        return $publisher;
    }

    /**
     * Creates a publisher with given properties.
     * @param  string $name
     * @param  string $url
     * @param  string $locale
     * @return Publisher Created publisher
     */
    private function createPublisher($name, $url, $locale)
    {
        $publisher = new Publisher();
        $publisher
            ->setCurrentLocale(mb_substr($locale, 0, 2, 'UTF-8'))
            ->setName($name)
            ->setEmail('publisher@example.com')
            ->setAddress('-')
            ->setPhone('-')
            ->setUrl($url);
        $this->em->persist($publisher);
        return $publisher;
    }

    /**
     * Creates a language with given language code.
     * @param  String $code Language code
     * @return Lang Created language
     */
    private function createLanguage($code)
    {
        $nameMap = array(
            'tr' => 'Türkçe',
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'ru' => 'Русский язык',
        );

        $lang = new Lang();
        $lang->setCode($code);
        !empty($nameMap[$code]) ?
            $lang->setName($nameMap[$code]) :
            $lang->setName('Unknown Language');

        $this->em->persist($lang);

        return $lang;
    }

    private function importSubjects($languageCode)
    {
        if (empty($this->settings[$languageCode]['categories'])) {
            return array();
        }

        $subjects = [];
        $categoryIds = unserialize($this->settings[$languageCode]['categories']);

        if (!is_array($categoryIds)) {
            return [];
        }

        foreach ($categoryIds as $categoryId) {
            $categorySql = "SELECT locale, setting_value FROM " .
                "controlled_vocab_entry_settings WHERE " .
                "controlled_vocab_entry_id = :categoryId";

            $categoryStatement = $this->dbalConnection->prepare($categorySql);
            $categoryStatement->bindValue('categoryId', $categoryId);
            $categoryStatement->execute();

            $pkpCategorySettings = $categoryStatement->fetchAll();
            $categorySettings = [];

            foreach ($pkpCategorySettings as $pkpSetting) {
                $locale = !empty($pkpSetting['locale']) ? $pkpSetting['locale'] : $languageCode;
                $value = $pkpSetting['setting_value'];
                $categorySettings[$locale] = $value;
            }

            $slug = Transliterator::urlize(array_values($categorySettings)[0]);
            $tags = str_replace(' ', ', ', strtolower(array_values($categorySettings)[0]));

            $subject = $this->em
                ->getRepository('VipaJournalBundle:Subject')
                ->findOneBy(['slug' => $slug]);

            if (!$subject) {
                $subject = new Subject();
                $subject->setSlug($slug);
                $subject->setTags($tags);

                foreach ($categorySettings as $locale => $value) {
                    $subject->setCurrentLocale(mb_substr($locale, 0, 2, 'UTF-8'));
                    $subject->setSubject($value);
                    $this->em->persist($subject);
                    $this->em->flush();
                }
            }

            $subjects[] = $subject;
        }

        return $subjects;
    }

    public function importContacts($languageCode)
    {
        $mainContact = new JournalContact();
        $contactName = !empty($this->settings[$languageCode]['contactName']) ?
            $this->settings[$languageCode]['contactName'] : null;
        $contactEmail = !empty($this->settings[$languageCode]['contactEmail']) ?
            $this->settings[$languageCode]['contactEmail'] : null;

        if ($contactName && $contactEmail) {
            $mainContact->setFullName($contactName);
            $mainContact->setEmail($contactEmail);

            if (!empty($this->settings[$languageCode]['contactPhone']))
                $mainContact->setPhone($this->settings[$languageCode]['contactPhone']);
            if (!empty($this->settings[$languageCode]['contactMailingAddress']))
                $mainContact->setAddress($this->settings[$languageCode]['contactMailingAddress']);
        }

        $supportContact = new JournalContact();
        $supportName = !empty($this->settings[$languageCode]['supportName']) ?
            $this->settings[$languageCode]['supportName'] : null;
        $supportEmail = !empty($this->settings[$languageCode]['supportEmail']) ?
            $this->settings[$languageCode]['supportEmail'] : null;

        if ($supportName && $supportEmail) {
            $supportContact->setFullName($supportName);
            $supportContact->setEmail($supportEmail);

            if (!empty($this->settings[$languageCode]['supportPhone']))
                $supportContact->setPhone($this->settings[$languageCode]['supportPhone']);
            if (!empty($this->settings[$languageCode]['mailingAddress']))
                $supportContact->setAddress($this->settings[$languageCode]['mailingAddress']);
        }

        $type = $this->em->getRepository('VipaJournalBundle:ContactTypes')->findBy([], null, 1);

        if ($type) {
            $mainContact->setContactType($type[0]);
            $supportContact->setContactType($type[0]);
        } else {
            $newType = new ContactTypes();
            $newType->setCurrentLocale(mb_substr($languageCode, 0, 2, 'UTF-8'));
            $newType->setName('Default');
            $newType->setDescription('Default Type');

            $this->em->persist($newType);

            $mainContact->setContactType($newType);
            $supportContact->setContactType($newType);
        }
        
        $this->journal->addJournalContact($mainContact);
        $this->journal->addJournalContact($supportContact);
    }

    public function importSubmissionChecklist($languageCode)
    {
        $checklist = new SubmissionChecklist();
        $checklist->setLabel('Checklist');
        $checklist->setLocale(mb_substr($languageCode, 0, 2, 'UTF-8'));

        $detail = "<ul>";

        if (!empty($this->settings[$languageCode]['submissionChecklist'])) {
            $items = unserialize($this->settings[$languageCode]['submissionChecklist']);

            if ($items) {
                foreach ($items as $item) {
                    $detail .= "<li>".$item['content']."</li>";
                }
            }
        }

        $detail .= "</ul>";

        $checklist->setDetail($detail);
        $this->journal->addSubmissionChecklist($checklist);
    }

    public function importAboutPage()
    {
        $page = new JournalPage();
        $page->setJournal($this->journal);

        foreach ($this->settings as $language => $field) {
            $content = '';

            !empty($field['focusScopeDesc']) && $content .= $field['focusScopeDesc'];
            !empty($field['reviewPolicy']) && $content .= $field['reviewPolicy'];
            !empty($field['reviewGuidelines']) && $content .= $field['reviewGuidelines'];
            !empty($field['authorGuidelines']) && $content .= $field['authorGuidelines'];
            !empty($field['authorInformation']) && $content .= $field['authorInformation'];
            !empty($field['readerInformation']) && $content .= $field['readerInformation'];
            !empty($field['librarianInformation']) && $content .= $field['librarianInformation'];
            !empty($field['authorSelfArchivePolicy']) && $content .= $field['authorSelfArchivePolicy'];
            !empty($field['publisherNote']) && $content .= $field['publisherNote'];
            !empty($field['pubFreqPolicy']) && $content .= $field['pubFreqPolicy'];
            !empty($field['openAccessPolicy']) && $content .= $field['openAccessPolicy'];
            !empty($field['privacyStatement']) && $content .= $field['privacyStatement'];
            !empty($field['copyrightNotice']) && $content .= $field['copyrightNotice'];
            !empty($field['copyeditInstructions']) && $content .= $field['copyeditInstructions'];

            $page->setCurrentLocale(mb_substr($language, 0, 2, 'UTF-8'));
            $page->setTitle('About');
            $page->setBody($content);
        }

        $this->em->persist($page);
    }
}
