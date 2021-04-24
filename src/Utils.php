<?php


namespace App;


use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class Utils
{
    public static function truncateTable(Connection $connection, string $table)
    {
        $platform = $connection->getDatabasePlatform();
        $sql = $platform->getTruncateTableSQL($table, false);
        $connection->executeStatement($sql);
    }

    public static function authorizeSheetsClient(\Google_Client $client, string $cacheDir, ?Command $command, ?InputInterface $input, ?OutputInterface $output)
    {
        if (!$client->isAccessTokenExpired())
            return;

        $client->setApplicationName('Gestionale CONAGRIVET');
        $client->setScopes(\Google_Service_Sheets::SPREADSHEETS_READONLY);

        // Tries to load access token from cache
        $tokenPath = "{$cacheDir}/google_api_token.json";
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                if (!$command || !$input || !$output) throw new \Exception("Cannot perform Google API authentication without console I/O");

                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                $output->writeln("Visit <info>{$authUrl}</info>");
                $questioner = $command->getHelper('question');
                $authCode = $questioner->ask($input, $output, new Question('Enter the verification code: '));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new \Exception(join(', ', $accessToken));
                }
            }

            // Caches the token.
//            if (!file_exists(dirname($tokenPath))) {
//                mkdir(dirname($tokenPath), 0700, true);
//            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
    }

    public static function getSheetNameFromId(\Google_Client $client, string $spreadsheetId, string $sheetId): ?string
    {
        assert(!$client->isAccessTokenExpired(), "Given Google_Client must have a valid access token");

        // Determines sheet name to be used in A1 notation
        $service = new \Google_Service_Sheets($client);
        $sheets = $service->spreadsheets->get($spreadsheetId)->getSheets();
        foreach ($sheets as $sheet) {
            if ($sheet->getProperties()->getSheetId() == $sheetId) {
                return $sheet->getProperties()->getTitle();
            }
        }

        return null;
    }
}
