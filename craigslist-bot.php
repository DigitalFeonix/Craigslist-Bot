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

    $include_nearby = key_exists('searchNearby', $search) ? $search['searchNearby'] : FALSE;

    $query = [];

    if (key_exists('q', $search))
    {
        $query['query'] = $search['q'];
    }

    if (key_exists('opt', $search))
    {
        $query = array_merge($query, $search['opt']);
    }

    $qs = http_build_query($query);

    // ensure category is array
    if (!is_array($search['cat']))
    {
        $search['cat'] = (array) $search['cat'];
    }

    foreach ($search['cat'] as $cat)
    {
        $url = sprintf(
            'https://%s.craigslist.org/search/%s?%s&purveyor-input=all',
            $search['loc'],
            $cat,
            $qs
        );

        $is_first_run = !array_key_exists($md5, $last_run);
        $this_run     = !$is_first_run ? $last_run[$md5] : 0;

        echo "Searching for {$name} at {$url}","\n";
        echo sprintf('last known posting was at %s (%s)', date('Y-m-d H:i:s', $this_run), $this_run), "\n";

        $html = file_get_contents($url);

        if ($html === FALSE)
        {
            error_log("failed to retrieve {$name}, moving to next search");
            sleep(15);
            continue;
        }

        $html = mb_convert_encoding($html, 'UTF-8');

        if (empty($html))
        {
            error_log("{$name} html was empty");
            continue;
        }

        // NOTE: two styles of `result-row` info (normal and repost)
        // <li class="result-row" data-pid="7433309959">
        // <li class="result-row" data-pid="7431387316" data-repost-of="7414048538">
        preg_match_all('#<li class="result-row" data-pid="([0-9]+)".*>(.*)</li>#imsU', $html, $matches);

        $results = array_combine($matches[1], $matches[2]);

        echo sprintf('parsing %d results for %s', count($results), $name), "\n";

        foreach ($results as $posting_id => $result_datum)
        {
            // <time class="result-date" datetime="2022-01-19 10:50" title="Wed 19 Jan 10:50:39 AM">Jan 19</time>
            preg_match('#<time class="result-date" datetime=".*" title="(.*)">.*</time>#i', $result_datum, $time_matches);
            // Convert "Thu 27 Jan 12:34:56 PM" to "Jan 27 12:34:56 PM"
            $new_time = preg_replace('#([A-z]{3}) ([0-9]{2}) ([A-z]{3})#i', '$3 $2', $time_matches[1]);
            $tstamp = strtotime($new_time);

            // if the timestamp happens to be in the future, force it back a year (this prevents December dates processed in January from being in the far future)
            if ($tstamp > $get + 86400)
            {
                $tstamp = strtotime($new_time, strtotime('-1 year'));
            }

            // skip if older than last known item
            if (!$is_first_run && $tstamp <= $last_run[$md5])
            {
                continue;
            }

            echo "found new listing posted at {$time_matches[1]}", "\n";

            // put the lastest item as the tstamp to beat
            if ($tstamp > $this_run)
            {
                $this_run = $tstamp;
            }

            // <h3 class="result-heading">
            //     <a href="https://seattle.craigslist.org/tac/clt/d/burton-spirit-halloween-25th-and-30th/7434958449.html" data-id="7434958449" class="result-title hdrlnk" id="postid_7434958449" >spirit halloween 25th AND 30th anniversary t-shirts- NEW</a>
            // </h3>
            preg_match('#<h3 class="result-heading">\s*<a href="(.*)" data-id="[0-9]+" class="result-title hdrlnk" id="postid_[0-9]+" >(.*)</a>#imsU', $result_datum, $heading_matches);

            // <span class="result-price">$125</span>
            preg_match('#<span class="result-price">(.*)</span>#iU', $result_datum, $price_matches);

            $is_local = TRUE;

            // NOTE: examples of local and nearby results
            // <span class="result-hood"> ( tacoma / pierce )</span>
            // <span class="nearby" title="bellingham, WA">(bli &gt; Ferndale  )</span>
            if (!preg_match('#<span class="result-hood">(.*)</span>#iU', $result_datum, $location_matches))
            {
                preg_match('#<span class="nearby" title=".*">(.*)</span>#iU', $result_datum, $location_matches);
                $is_local = FALSE;
            }

            // if we don't want nearby, additional checks need to be made
            if (!$include_nearby)
            {
                // CL has marked them as nearby
                if (!$is_local)
                {
                    echo 'skipping because not local', "\n";
                    continue;
                }

                // if search_distance is a param, then nearby could still be from same local sub-domain, check the distance
                // <span class="maptag">10.2mi</span>
                if (key_exists('opt', $search) && key_exists('search_distance', $search['opt']))
                {
                    preg_match('#<span class="maptag">([0-9]+(\.[0-9]+)?)mi</span>#', $result_datum, $distance_match);

                    if ($distance_match[1] > $search['opt']['search_distance'])
                    {
                        echo 'skipping because outside of search distance', "\n";
                        continue;
                    }
                }
            }

            $item = [
                'date' => $time_matches[1],
                'link' => $heading_matches[1],
                'title' => $heading_matches[2],
                'price' => $price_matches[1],
                'location' => sprintf('( %s )', trim($location_matches[1], " \n\r\t\v\x00()")), // consistent parentheses
                'description' => '',
                'images' => []
            ];

            // NOTE: examples of images, and no images
            // <a href="https://seattle.craigslist.org/tac/clt/d/burton-spirit-halloween-25th-and-30th/7434958449.html" class="result-image gallery" data-ids="3:00q0q_4WP6WZ2QGC4z_07K0ak,3:00x0x_gXtzJFqrHkcz_07K0ak">
            // <a href="https://seattle.craigslist.org/kit/wan/d/bainbridge-island-wanted-vintage/7428397438.html" class="result-image gallery empty"></a>
            if (preg_match('#<a href=".*" class="result-image gallery" data-ids="(.*)">#iU', $result_datum, $image_matches))
            {
                $image_list = explode(',', $image_matches[1]);

                foreach ($image_list as $image_datum)
                {
                    list(,$image_id) = explode(':', $image_datum);
                    $item['images'][] = sprintf('https://images.craigslist.org/%s_300x300.jpg', $image_id);
                }
            }

            // put this listing into the output
            $html_message .= '<h3 style="clear:left;margin-bottom:0px;"><a href="'.$item['link'].'">'.$item['title'].' -- '.$item['price'].'</a></h3>'."\n";
            $html_message .= '<h5 style="margin:0px;">'.$item['location'].'</h5>'."\n";
            $html_message .= '<h5 style="margin-top:0px;">Posted '.$item['date'].'</h5>'."\n";
            $html_message .= '<div>'."\n";

            foreach ($item['images'] as $image_src)
            {
                $html_message .= '<a href="'.$item['link'].'">';
                $html_message .= '<img src="'.$image_src.'" style="float:left;margin-right:5px;margin-bottom:10px;"></a>'."\n";
            }

            $html_message .= $item['description']."\n";
            $html_message .= '</div>'."\n";
        }
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
