<?php

namespace App;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

//use function ByteUnits\bytes;

class App
{
    /**
     * @var SymfonyStyle
     */
    protected $io;
    /**
     * @var Remote
     */
    private $remote;
    
    protected $numOfAttempts;
    
    /**
     * Constructor.
     *
     * @param              $username
     * @param              $password
     * @param SymfonyStyle $io
     */
    public function __construct($username, $password, $io)
    {
        $this->io            = $io;
        $this->remote        = new Remote($username, $password, $io);
        $this->numOfAttempts = 3;
    }
    
    /**
     * @throws Exception
     */
    public function retrying($url)
    {
        $attempts = 0;
        do {
            try {
                if (false === ($remoteFileData = get_headers($url, 1))) {
                    error("attempts: {$attempts}, Can't get file size {$url}");
                    throw new Exception("Can't get file size {$url}");
                }
                
                return $remoteFileData;
            } catch (Throwable $e) {
                $attempts++;
                sleep(5);
                continue;
            }
            // break;
        } while ($attempts < $this->numOfAttempts);
    }
    
    public function getFileSize($url)
    {
        $remoteFileData = get_headers($url, 1);
        if (!$remoteFileData) {
            error("Can't get file size {$url}");
        }
    }
    
    /**
     * Download.
     *
     * @param       $output
     * @param array $courses
     * @throws Exception
     */
    public function download($output, $courses = [])
    {
        // if we have an empty series we will fetch all series
        if (empty($courses)) {
            $remote = $this->remote->meta();
            
            // Meta holds information about courses.
            $meta = $remote->meta;//->pagination;
            success("Total {$meta->total} lessons found, fetching  {$meta->last_page} pages for courses.");
            
            // And as a first page of courses lets get first page of courses.
            $courses = collect($remote->data)->pluck('slug')->toArray();
            
            $info = collect($remote->data)->mapWithKeys(
                function ($item) {
                    return [$item->slug => ['id' => $item->id, 'title' => $item->title]];
                }
            );
            
            // Lets create symfony progress bar instance.
            $progressBar = new ProgressBar($output, $meta->last_page);
            
            // We can customize progress bar with custom messages.
            $progressBar->setFormat("%status%\n%current%/%max%  [%bar%] %percent:3s%%\n");
            
            // Lets sets initial message.
            $progressBar->setMessage('Gathering data...', 'status');
            
            // Lets get the rest of the courses.
            for ($i = 2; $i <= $meta->last_page; ++$i) {
                $progressBar->advance();
                $progressBar->setMessage("Fetching page: {$i}", 'status');
                // Getting pages from codecourse api.
                $slugs = $this->remote->page($i);
                
                // And lets merge all lessons to single array.
                $courses = [...$courses, ...$slugs->pluck('slug')->toArray()];//array_merge($courses, $slugs->pluck('slug')->toArray());
                
                $pageData = collect($slugs)->mapWithKeys(
                    function ($item) {
                        return [$item->slug => ['id' => $item->id, 'title' => $item->title]];
                    }
                );
                $info     = $info->merge($pageData);
            }
            
            $progressBar->setMessage('Fetching pages completed.', 'status');
            
            $progressBar->finish();
        }
        
        // So if courses array given by user we will use that otherwise we will download all courses.
        $courses = collect($courses);
        
        $files = new FileLister();
        
        foreach ($courses as $course) {
            //skip courses if needed
            /*if (isset($info[$course]['id']) && getenv('from') && getenv('from') <= $info[$course]['id']) {
                success('Skipping course: ' . $info[$course]['id'] . '-' . $info[$course]['title']);
                continue;
            }*/
            
            $courseTitle = isset($info[$course]['id']) ? "{$info[$course]['id']}-{$info[$course]['title']}" : $course;
            
            // Lets check is there any directory with course slug.
            if (!$files->exists($courseTitle)) {
                // otherwise create directory
                $files->file->createDirectory($courseTitle);
            }
            
            // Get single course and get lessons from it.
            $lessons = $this->remote->getCourse($course)->getPage();
            
            // Progressbar
            $progressBar = new ProgressBar($output, count($lessons));
            $progressBar->setFormat("%status%\n%current%/%max%  [%bar%] %percent:3s%%\n");
            $progressBar->setMessage('Gathering course data', 'status');
            
            $progressBar->start();
            
            foreach ($lessons as $lesson) {
                // Filename with full path.
                $sink = getenv('DOWNLOAD_FOLDER') . "/{$courseTitle}/{$lesson->filename}";
                
                // if we have file we will skip.
                if (!$files->exists("{$courseTitle}/{$lesson->slug}")) {
                    $url = $this->remote->getRedirectUrl($lesson);
                    
                    //todo: move to a new class
                    $remoteFileData = $this->retrying($url);
                    if (!$remoteFileData) {
                        error("Can't get file size ({$courseTitle}): {$lesson->title}");
                        continue;
                    }
                    $remoteUrlFileSize = array_change_key_case($remoteFileData, CASE_LOWER)["content-length"];
                    
                    $existingFileSize = file_exists($sink) ? filesize($sink) : 0;
                    
                    //check if file is not downloaded or if the file sizes are different
                    if (!file_exists($sink) || $existingFileSize != (int)$remoteUrlFileSize) {
                        $progressBar->setMessage(
                            "1Downloading ({$courseTitle}): {$lesson->title} - Sizes: {$existingFileSize}:{$remoteUrlFileSize}" . ($existingFileSize === (int)$remoteUrlFileSize ? ' Same' : ' Not Same'),
                            'status'
                        );
                        $progressBar->advance();
                        try {
                            $this->remote->web->request(
                                'GET',
                                $url,
                                [
                                    'sink'     => $sink,
                                    'progress' => function ($dl_total_size, $dl_size_so_far, $ul_total_size, $ul_size_so_far) use ($remoteUrlFileSize, $existingFileSize, $progressBar, $courseTitle, $lesson) {
                                        $total      = \ByteUnits\bytes($dl_total_size)->format('MB');
                                        $sofar      = \ByteUnits\bytes($dl_size_so_far)->format('MB');
                                        $percentage = $dl_total_size != '0.00' ? number_format($dl_size_so_far * 100 / $dl_total_size) : 0;
                                        $progressBar->setMessage(" Sizes: {$existingFileSize}:{$remoteUrlFileSize} - Downloading ({$courseTitle}): {$lesson->title} {$sofar}/{$total} ({$percentage}%)", 'status');
                                        // It takes too much time to figure this line. Without advance() it was not update message.
                                        // With  this method i can update message.
                                        $progressBar->display();
                                    },
                                ]
                            );
                        } catch (Exception $e) {
                            //error("Cant download '{$lesson->title}'. Do you have active subscription?");
                            continue;
                        } catch (GuzzleException $e) {
                            continue;
                            //error("Cant download '{$lesson->title}'. Do you have active subscription?");
                        }
                    } else {
                        $progressBar->setMessage(
                            "Skipping, already downloaded ({$courseTitle}): {$lesson->title} - Sizes: {$existingFileSize}:{$remoteUrlFileSize}" . ($existingFileSize === (int)$remoteUrlFileSize ? ' Same' : ' Not Same'),
                            'status'
                        );
                        $progressBar->advance();
                    }
                }
            }
            $progressBar->setMessage('All videos downloaded for course: ' . $courseTitle, 'status');
            
            $progressBar->finish();
        }
    }
}
