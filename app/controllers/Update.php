<?php

class Update extends Controller
{
    /**
     * Renders the update index and returns the content.
     *
     * @return string
     */
    public function index()
    {
        $this->view->setTitle('Update');
        $this->view->renderTemplate('system/update');

        // Make sure the platform is not already up-to-date
        $version = $this->model('Setting')->get('version');
        if ($version === version) {
            throw new Exception('ezXSS is already up-to-date');
        }

        try {
            $this->view->renderData('tablesize', 'Table size: ' . ceil(($this->getReportsSize() * 1.1) / (1024*1024)) . ' MB');
        } catch (Exception) {
            $this->view->renderData('tablesize', 'Error in retrieving table size. Proceed with caution');
        }

        if ($this->isPOST()) {
            try {
                $this->validateCsrfToken();

                // Check if the version is 1.x or 2.x
                if (preg_match('/^1\./', $version) || preg_match('/^2\./', $version)) {
                    throw new Exception('Please first update to 3.0 before migrating to 4.x');
                }

                //todo: 3.x - 3.10

                // Update the database from 3.10/3.11 to 4.0
                if ($version === '3.10' || $version === '3.11') {
                    $this->ezXSS3migrate();
                    $version = '4.0';
                    $this->model('Setting')->set('version', $version);
                }

                // Update the database from 4.0 to 4.1
                if ($version === '4.0') {
                    $sql = file_get_contents(__DIR__ . '/../sql/4.0-4.1.sql');
                    $database = Database::openConnection();
                    $database->exec($sql);
                    $version = '4.1';
                    $this->model('Setting')->set('version', $version);
                }

                // Update the database from 4.1 to 4.2
                if ($version === '4.1') {
                    try {
                        // Check if disk has enough free space
                        $tableSize = $this->getReportsSize();
                        if($tableSize * 1.1 > disk_free_space('/')) {
                            $freeSpace = ceil(disk_free_space('/') / (1024*1024));
                            $tableSize = ceil(($tableSize * 1.1) / (1024*1024));
                            throw new Exception("Error in updating. Free space on disk is {$freeSpace} MB and temporary needed space for table is {$tableSize} MB. Please upgrade disk");
                        }
                    } catch (Exception $e) {
                        if($this->getGetValue('disablechecks') !== '1') {
                            throw new Exception($e->getMessage() . "\r\nYou can disable this check by adding ?disablechecks=1 to the URL\r\nWARNING: If table is larger than free disk size, database can get corrupted");
                        }
                    }

                    $sql = file_get_contents(__DIR__ . '/../sql/4.1-4.2.sql');
                    $database = Database::openConnection();
                    $database->exec($sql);
                }

                $this->model('Setting')->set('version', version);
                redirect('update/migrateScreenshots');
            } catch (Exception $e) {
                $this->view->renderMessage($e->getMessage());
            }
        }

        return $this->showContent();
    }

    /**
     * Migrate old screenshots images to database
     * 
     * @return void
     */
    public function migrateScreenshots()
    {
        try {
            $screenshots = glob(__DIR__ . '/../../assets/img/report-*.png');

            if ($screenshots === []) {
                throw new Exception('No screenshots left to migrate');
            }
        } catch (Exception $e) {
            redirect('dashboard/index');
        }

        $errors = [];
        foreach ($screenshots as $screenshot) {
            try {
                $screenshotName = str_replace('report-', '', pathinfo($screenshot, PATHINFO_FILENAME));
                $screenshotData = base64_encode(file_get_contents($screenshot));

                $reportId = $this->model('Report')->getIdByScreenshot($screenshotName);
                $this->model('Report')->setSingleDataValue($reportId, 'screenshot', $screenshotData);

                unlink($screenshot);
            } catch (Exception $e) {
                $errors[] = "Error in migrating ({$e}) for " . basename($screenshot);
            }
        }

        if (debug && $errors !== []) {
            throw new Exception(implode("\r\n", $errors));
        }
    }

    /**
     * Migrate ezXSS 3 database to ezXSS 4
     * 
     * @return void
     */
    private function ezXSS3migrate()
    {
        // Store old data
        $password = $this->model('Setting')->get('password');
        $notepad = $this->model('Setting')->get('notepad');

        // Update the database tables and rows
        $sql = file_get_contents(__DIR__ . '/../sql/3.x-4.0.sql');
        $database = Database::openConnection();
        $database->exec($sql);
        $database->exec('ALTER DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;');

        $this->model('Setting')->set('version', version);

        // Create new user and update old 
        $user = $this->model('User')->create('admin', 'Temp1234!', 7);
        $this->model('User')->setPassword($user['id'], $password, true);

        $this->model('Payload')->add($user['id'], host);

        // Add note
        $this->model('Setting')->set('notepad', "Great! U have updated to ezXSS 4!\n\nA lot of things have changed, and some settings like your alerts and payloads needs to be re-done in other to make everything work correct again.\n\nPlease visit the Github wiki for help on github.com/ssl/ezXSS/wiki\n\n" . $notepad);

        // Update all oldskool 'collected pages' and NULL payloads
        $reports = $this->model('Report')->getAllInvalid();
        foreach ($reports as $report) {
            // Set payload to current host
            $this->model('Report')->setSingleValue($report['id'], 'payload', '//' . host . '/');

            // Set refer to collected if collected is set
            if (strpos($report['payload'], 'Collected page via ') === 0) {
                $this->model('Report')->setSingleValue($report['id'], 'referer', $report['payload']);
            }
        }
    }

    /**
     * Get size in bytes from reports table
     * 
     * @return int
     */
    private function getReportsSize() {
        $database = Database::openConnection();
        $database->prepare('SELECT data_length + index_length AS total_size FROM information_schema.tables WHERE table_schema = "' . DB_NAME . '" AND table_name = "reports"');
        $database->execute();
        $tableSize = $database->fetch();
        return $tableSize['total_size'];
    }
}
