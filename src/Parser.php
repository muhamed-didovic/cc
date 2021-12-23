<?php

namespace App;

use Symfony\Component\DomCrawler\Crawler;

class Parser
{
    /**
     * @var Crawler
     */
    public $crawler;
    
    public $jsfunction;
    
    public $lessons;
    
    /**
     * Parse HTML.
     *
     * @param $html
     *
     * @return $this
     */
    public function parse($html)
    {
        $this->lessons = $html;
        
        return $this;
    }
    
    public function getPage()
    {
        $lessons = [];
        foreach ($this->lessons->data as $lesson) {
            $lesson    = (object)[
                'link'        => "https://codecourse.com/api/parts/" . $lesson->id . "/download",
                'title'       => $lesson->title,
                'slug'        => $lesson->slug,
                'filename'    => sprintf('%02d', (int)$lesson->order - 1) . '-' . $lesson->slug . '.mp4',
                'provider_id' => $lesson->video->vimeo_id ?? null, //$lesson->file->data->stream->data->provider_id ?? null,
                'download'    => $lesson->file->data->download ?? null,
            ];
            $lessons[] = $lesson;
        }
        
        return $lessons;
    }
}
