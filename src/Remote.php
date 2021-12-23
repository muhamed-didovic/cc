<?php

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Style\SymfonyStyle;

class Remote
{
    /**
     * @var SymfonyStyle
     */
    public $io;
    /**
     * @var Client
     */
    public $web;
    
    /**
     * @var CookieJar
     */
    public $cookie;
    
    /**
     * @var Parser
     */
    public $parser;
    
    public $api;
    
    public $token;
    
    public $version;
    
    public function __construct($username, $password, $io)
    {
        $this->cookie = new CookieJar();
        $this->web    = new Client(['verify' => false, 'base_uri' => getenv('BASE_URL')]);
        //        $this->api    = new Client(['verify' => false, 'base_uri' => getenv('API')]);
        $this->parser = new Parser();
        
        $this->login($username, $password);
        $this->io = $io;
    }
    
    /**
     * Login.
     *
     * @param $username
     * @param $password
     *
     * @throws \Exception
     */
    public function login($username, $password)
    {
        try {
            [$sanitizeXsrfToken, $cookie, $version] = $this->getNecessaryTokensForLogin();
            $this->version = $version;
            $response      = $this->web->request('POST', getenv('LOGIN_PATH'), [
                'cookies'     => $this->cookie,
                'headers'     => [
                    'content-type' => 'application/json',
                    "x-xsrf-token" => $sanitizeXsrfToken,
                    "cookie"       => $cookie,
                ],
                'form_params' => [
                    'email'    => $username,
                    'password' => $password,
                    'remember' => true,
                ],
            ]);
            // $content       = json_decode($response->getBody());
            success('Logged in successfully, collecting courses.');
        } catch (GuzzleException $e) {
            error("Can't login to website.");
            exit;
        } catch (\Exception $e) {
            error('Error on login: ' . $e->getMessage());
            exit;
        }
    }
    
    public function getNecessaryTokensForLogin()
    {
        try {
            $response = $this->web->request('GET', getenv('BASE_URL') . '/auth/signin');
            $string   = $response->getBody()->getContents();
            
            //find verdsion for inertia
            preg_match('/&quot;version&quot;:&quot;(.*?)&quot;}/', $string, $matches);
            
            //sanitize tokens
            [$xsrfToken, $codecourseSession] = $response->getHeaders()['Set-Cookie'];
            $xsrfToken         = explode('%3D;', $xsrfToken)[0] . '%3D;';
            $codecourseSession = explode('%3D;', $codecourseSession)[0] . '%3D;';
            $cookie            = "{$xsrfToken} {$codecourseSession}";
            $sanitizeXsrfToken = explode('XSRF-TOKEN=', $xsrfToken)[1];
            $sanitizeXsrfToken = explode('%3D;', $sanitizeXsrfToken)[0] . "=";
            
            success('Got the tokens for login.');
            
            return [$sanitizeXsrfToken, $cookie, $matches[1]];
        } catch (\Exception $e) {
            error("Can't get tokens.");
            exit;
        }
    }
    
    public function getCourse($slug)
    {
        try {
            $response = $this->web->request('GET', getenv('BASE_URL') . "/watch/{$slug}", [
                'cookies'  => $this->cookie,
                'base_uri' => getenv('BASE_URL'),
                'headers'  => [
                    'x-inertia-version' => $this->version,
                    'x-inertia'         => 'true',
                ],
            ]);
            
            $data     = json_decode($response->getBody());
            return (new Parser())->parse($data->props->parts);
        } catch (GuzzleException $e) {
            error("Can't fetch course url");
        }
    }
    
    public function meta()
    {
        try {
            $api  = $this->web->request('GET', getenv('LIBRARY'), [
                'cookies'  => $this->cookie,
                'base_uri' => getenv('BASE_URL'),
                'headers'  => [
                    'x-inertia-version' => $this->version,
                    'x-inertia'         => 'true',
                ],
            ]);
            
            $data = json_decode($api->getBody());
            return $data->props->courses;
        } catch (GuzzleException $e) {
            error("Can't fetch courses.");
            exit;
        }
    }
    
    public function page($number)
    {
        try {
            $courses = $this->web->request('GET', getenv('LIBRARY') . "?page={$number}", [
                'cookies'  => $this->cookie,
                'base_uri' => getenv('BASE_URL'),
                'headers'  => [
                    'x-inertia-version' => $this->version,
                    'x-inertia'         => 'true',
                ],
            ]);
            $courses = json_decode($courses->getBody());
    
            return collect($courses->props->courses->data);
        } catch (GuzzleException $e) {
            error("Can't fetch course page.");
            exit;
        }
    }
    
    /**
     * @param        $course
     * @param string $lesson
     *
     * @throws GuzzleException
     */
    public function downloadFile($course, $lesson)
    {
        try {
            $url  = $this->getRedirectUrl($lesson->link);
            $sink = getenv('DOWNLOAD_FOLDER') . "/{$course}/{$lesson->filename}";
            $this->web->request('GET', $url, ['sink' => $sink]);
        } catch (\Exception $e) {
            error("Cant download '{$lesson->title}'. Do you have active subscription?");
            exit;
        }
    }
    
    public function getRedirectUrl($lesson)
    {
        try {
            $response = $this->web->request('POST', $lesson->link, [
                'cookies' => $this->cookie,
                'headers' => [
                    'authorization' => 'Bearer ' . $this->token,
                ],
            ]);
            
            $content = json_decode($response->getBody(), true);
            return $content['data'];
        } catch (GuzzleException $e) {
            
            //if we can't download it normally, we need to go vimeo and find neccesary videos and find the biggest one
            $url = "https://player.vimeo.com/video/{$lesson->provider_id}?h=93dc93917d&title=0&byline=0&app_id=122963";
            $response = $this->web->request('GET', $url, [
                'headers' => [
                    'Referer' => 'https://codecourse.com/',
                ],
            ]);

            $string = $response->getBody()->getContents();
            preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $string, $match);
            $link = collect($match)
                ->flatten()
                ->filter(function ($item) {
                    return (!empty($item) && strpos($item, 'https://vod-progressive.akamaized.net') !== false) ? true : false;;
                })
                ->map(function ($url) {
                    $res = get_headers($url, 1);
                    return [
                        'url' => $url,
                        'size' => array_change_key_case($res, CASE_LOWER)["content-length"]
                    ];
                    
                })
                ->reduce(fn($a, $b) => $a ? ($a['size'] > $b['size'] ? $a : $b) : $b); //we sort them by size and take one that is the biggest
           
            return $link['url'];
            
//            error("Can't fetch redirect url");
        }
        
        return false;
    }
    
    /**
     * Create folder if does't exist.
     *
     * @param $folder
     * @param $file
     */
    public function createFolder($folder, $file)
    {
        if ($file->file->has($folder) === false) {
            $file->file->createDir($folder);
        }
    }
}
