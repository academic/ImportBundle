<?php

namespace Vipa\ImportBundle\Importer\PKP;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Faker\Factory;
use FOS\UserBundle\Model\UserManager;
use FOS\UserBundle\Util\TokenGenerator;
use Vipa\UserBundle\Entity\User;
use Vipa\ImportBundle\Helper\ImportHelper;
use Vipa\ImportBundle\Importer\Importer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserImporter extends Importer
{
    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @var string
     */
    private $locale;

    /**
     * UserImporter constructor.
     * @param Connection $dbalConnection
     * @param EntityManager $em
     * @param OutputInterface $consoleOutput
     * @param LoggerInterface $logger
     * @param UserManager $userManager
     * @param TokenGenerator $tokenGenerator
     * @param string $locale
     */
    public function __construct(
        Connection $dbalConnection,
        EntityManager $em,
        LoggerInterface $logger,
        OutputInterface $consoleOutput,
        UserManager $userManager,
        TokenGenerator $tokenGenerator,
        $locale
    )
    {
        parent::__construct($dbalConnection, $em, $logger, $consoleOutput);
        $this->userManager = $userManager;
        $this->tokenGenerator = $tokenGenerator;
        $this->locale = $locale;
    }

    /**
     * Imports the given user
     * @param int $id Old ID
     * @param bool|true $flush Should be true if the entity should get flushed
     * @return null|User Newly imported user
     * @throws \Doctrine\DBAL\DBALException
     */
    public function importUser($id, $flush = true)
    {
        $this->consoleOutput->writeln("Reading user #" . $id . "... ", true);

        $sql = "SELECT * FROM users WHERE users.user_id = :id " . ImportHelper::spamUsersFilterSql();
        $statement = $this->dbalConnection->prepare($sql);
        $statement->bindValue('id', $id);
        $statement->execute();

        $faker = Factory::create();
        $pkpUser = $statement->fetch();
        $user = null;

        if ($pkpUser) {
            if (!empty($pkpUser['username'])) {
                $user = $this->em->getRepository('VipaUserBundle:User')->findOneBy(['username' => $pkpUser['username']]);

                if (!$user) {
                    $user = $this->em->getRepository('VipaUserBundle:User')->findOneBy(['email' => $pkpUser['email']]);
                }
            }

            if (is_null($user)) {
                $user = new User();
                !empty($pkpUser['username']) ?
                    $user->setUsername($pkpUser['username']) :
                    $user->setUsername($faker->userName);

                !empty($pkpUser['email']) ?
                    $user->setEmail($pkpUser['email']) :
                    $user->setEmail($faker->companyEmail);

                !empty($pkpUser['disabled']) ?
                    $user->setEnabled(!$pkpUser['disabled']) :
                    $user->setEnabled(1);

                // Set a random password
                $password = mb_substr($this->tokenGenerator->generateToken(), 0, 8);
                $user->setPlainPassword($password);

                // Fields which can't be blank
                !empty($pkpUser['first_name']) ? $user->setFirstName($pkpUser['first_name']) : $user->setFirstName('Anonymous');
                !empty($pkpUser['last_name']) ? $user->setLastName($pkpUser['last_name']) : $user->setLastName('Anonymous');

                // Optional fields
                !empty($pkpUser['billing_address']) && $user->setBillingAddress($pkpUser['billing_address']);
                !empty($pkpUser['mailing_address']) && $user->setAddress($pkpUser['mailing_address']);
                !empty($pkpUser['gender']) && $user->setGender($pkpUser['gender']);
                !empty($pkpUser['phone']) && $user->setPhone($pkpUser['phone']);
                !empty($pkpUser['fax']) && $user->setFax($pkpUser['fax']);
                !empty($pkpUser['url']) && $user->setUrl($pkpUser['url']);
                
                $this->em->persist($user);

                if ($flush) {
                    $this->em->flush();
                }
            }

            return $user;
        }

        return null;
    }
}
