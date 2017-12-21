<?php

require_once __DIR__ . '/vendor/autoload.php';

$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
$signature = $_SERVER["HTTP_" . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

Predis\Autoloader::register();
$r = new Predis\Client(array(
    'host' => parse_url(getenv('REDIS_URL'), PHP_URL_HOST),
    'port' => parse_url(getenv('REDIS_URL'), PHP_URL_PORT),
    'password' => parse_url(getenv('REDIS_URL'), PHP_URL_PASS),
));

$events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
foreach ($events as $event) {

  $profile = $bot->getProfile($event->getUserId())->getJSONDecodedBody();
  $displayName = $profile['displayName'];

  if ($event instanceof \LINE\LINEBot\Event\MessageEvent) {
    if ($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage) {
      if($event->getText() === 'show') {
        $result = '';
        foreach($r->keys('lm_*') as $h) {
          $result = $result . (var_export($r->hgetall($h), true) + "\n");
        }
        $bot->replyMessage($event->getReplyToken(),
          (new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder())
            ->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('「こんにちは」と呼びかけて下さいね！'))
            ->add(new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(1, 4))
        );
        return;
      }

      if(in_array($event->getText(), array('comment', 'review')) && $r->hget($event->getUserId(), 'tmp') != null) {
        $r->hset($event->getUserId(), $event->getText(), $r->hget($event->getUserId(), 'tmp'));
        $r->hdel($event->getUserId(), 'tmp');
        notifyBlankField($event);
      } else {
        $r->hset($event->getUserId(), 'tmp', $event->getText());
        $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
          'Alternative Text',
          new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder (
            null,
            "Which field to store '{$event->getText()}'?",
            null,
            array(new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder("comment", "comment"),
                  new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder ("review", "review"))
          )
        );
        $response = $bot->replyMessage($event->getReplyToken(), $builder);
        if (!$response->isSucceeded()) {
          error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
        }
      }
    }
    else if ($event instanceof \LINE\LINEBot\Event\MessageEvent\LocationMessage) {
      $lat =  $event->getLatitude();
      $lon =  $event->getLongitude();

      $r->hmset($event->getUserId(), array('lat' => $lat, 'lon' => $lon));

      notifyBlankField($event);
    }
    else if($event instanceof \LINE\LINEBot\Event\MessageEvent\ImageMessage) {
      \Cloudinary::config(array(
        'cloud_name' => getenv('CLOUDINARY_NAME'),
        'api_key' => getenv('CLOUDINARY_KEY'),
        'api_secret' => getenv('CLOUDINARY_SECRET')
      ));

      $response = $bot->getMessageContent($event->getMessageId());
      $im = imagecreatefromstring($response->getRawBody());

      if ($im !== false) {
          $filename = uniqid();
          $directory_path = 'tmp';
          if(!file_exists($directory_path)) {
            if(mkdir($directory_path, 0777, true)) {
                chmod($directory_path, 0777);
            }
          }
          imagejpeg($im, $directory_path. '/' . $filename . '.jpg', 75);
      }

      $path = dirname(__FILE__) . '/' . $directory_path. '/' . $filename . '.jpg';
      $result = \Cloudinary\Uploader::upload($path);

      $r->hset($event->getUserId(), 'url', $result['secure_url']);

      notifyBlankField($event);
    }

    continue;
  }
}

function notifyBlankField($event) {
  global $r, $bot;

  $required = array('lat', 'lon', 'url', 'comment', 'review');
  $done = $r->hkeys($event->getUserId());

  $blank = array_diff($required, $done);

  if(count($blank) == 0) {
    $r->hset($event->getUserId(), 'userid', $event->getUserId());
    $r->rename($event->getUserId(), 'lm_' . uniqid());
    $bot->replyMessage($event->getReplyToken(),
        (new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder())
          ->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('added landmark. You can egister another landmark or view all data by sending \'show\''))
      );
  } else {
    $bot->replyMessage($event->getReplyToken(),
        (new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder())
          ->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('saved. required: ' . implode(", ", $blank)))
      );
  }
}

?>
