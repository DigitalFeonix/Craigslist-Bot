# Craigslist Bot

This is a simple script that will search Craigslist for you and return any result it finds via email.

The `lib.email.php` and `lib.network.php` files from [DigitalFeonix/Globals-Library](https://github.com/DigitalFeonix/Globals-Library)
are required. Place them somewhere in the PHP include path.

First copy the `craigslist-bot.*` files onto a server with PHP.

You will need to edit `craigslist-bot.php` to adjust the email address that the emails will be coming from in the
following bit of code.

```php
send_multipart_email(
    $search['ret'],
    'webmaster@example.com',
    'CraigslistBot Results for '.$name,
    $text_message,
    $html_message,
    array_key_exists('bcc', $search) ? $search['bcc'] : ''
);
```

Then edit the `craigslist-bot.cfg` file to match the parameters of your searches. (The one in the repo has several examples)

Each section/search starts with puting a unique name in brackets. This will be used as part of the subject line for the
email when it sends results.

* `loc` is the city you are searching. When you go to Craigslist it will be part of the domain like `https://seattle.craigslist.org/`
* `cat` is the 3 character code for the category you are searching under. To get this, navigate the website to the
  category you are interested in and it will be at the end of the URL. For example, the overall Jobs page for Seattle is
  `https://seattle.craigslist.org/d/jobs/search/jjj` the code `jjj` at the end is what you are looking for.
* `q` is the query to search for. You can do some interesting combinations, but will have to be quoted if it is not a
  single word.
* `opt[param]` is for the optional query parameters you can use when searching categories
  * the `param` will be replaced with the parameter wanted such as `postal` to denote the US Postal Code that you want to center
    a search around. This would usually be used in conjunction with `search_distance` to limit the radius of the search area.
* `ret` is the email address you would like to send the results to.
* `bcc` is an optional email you could have it BCC'd to

Finally, add the script to crontab in whatever schedule you like
```
0 9,12,15,18,21 * * * /home/user/craigslist-bot.php
```

Sit back and watch as the emails roll in.
