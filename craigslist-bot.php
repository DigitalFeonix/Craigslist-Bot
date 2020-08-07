#!/usr/bin/php
<?php

ini_set('default_socket_timeout', 15);
ini_set('user_agent', 'CraigslistBot/0.1');
include_once('lib.email.php');

$last_run = unserialize(file_get_contents('craigslist-bot.dat'));
$searches = parse_ini_file('craigslist-bot.cfg', TRUE);

foreach ($searches as $name => $search)
{
    $md5 = md5(serialize($search));
    $get = time();

    $text_message = 'Get a real email client!';
    $html_message = '';

    $url = sprintf(
        'https://%s.craigslist.org/search/%s?query=%s&format=rss',
        $search['loc'],
        $search['cat'],
        urlencode($search['q'])
    );

    $xml = file_get_contents($url);

    if ($xml === FALSE)
    {
        sleep(15);
        continue;
    }

    $xml = mb_convert_encoding($xml,'UTF-8');

    if (empty($xml))
    {
        error_log('xml was empty');
        continue;
    }

    $rss = simplexml_load_string($xml);

    if (!is_object($rss))
    {
        error_log('rss failed to load');
        continue;
    }

    $syn = $rss->channel->children('syn', TRUE);

    $is_first_run = !array_key_exists($md5, $last_run);
    $this_run     = !$is_first_run ? $last_run[$md5] : 0;

    foreach ($rss->item as $item)
    {
        $enc = $item->children('enc', TRUE);
        $dc = $item->children('dc', TRUE);

        $tstamp = strtotime($dc->date);

        // put the lastest item as the tstamp to beat
        if ($tstamp > $this_run) $this_run = $tstamp;

        // skip if older than last known item
        if (!$is_first_run && $tstamp <= $last_run[$md5]) continue;

        $html_message .= '<h3 style="clear:left;margin-bottom:0px;"><a href="'.$item->link.'">'.$item->title.'</a></h3>'."\n";
        $html_message .= '<h5 style="margin-top:0px;">Posted '.date('Y-m-d H:i:s', $tstamp).'</h5>'."\n";
        $html_message .= '<div>'."\n";
        if (count($enc) > 0)
        {
            $html_message .= '<a href="'.$item->link.'">';
            $html_message .= '<img src="'.$enc->enclosure->attributes()->resource.'" style="float:left;margin-right:5px;margin-bottom:10px;"></a>'."\n";
        }
        $html_message .= $item->description."\n";
        $html_message .= '</div>'."\n";
    }

    $last_run[$md5] = $this_run;

    if ($html_message != '')
    {
        send_multipart_email(
            $search['ret'],
            'webmaster@example.com',
            'CraigslistBot Results for '.$name,
            $text_message,
            $html_message,
            array_key_exists('bcc', $search) ? $search['bcc'] : ''
        );
    }

    // be nice and not slam the servers
    sleep( mt_rand(5,15) );
}

file_put_contents('craigslist-bot.dat', serialize($last_run));
