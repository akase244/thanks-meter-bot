# thanks-meter-bot

Slackの日々の投稿の中から感謝の言葉を抜き出して可視化するbotです。

以下の箇所を環境に応じて書き換えてください。

- `app/Console/Commands/ThanksMeter.php`

```
// Slack Webhook URL
private $slack_webhook_url = 'https://hooks.slack.com/services/REPLACE_YOUR_ACCESS_TOKEN'; // REPLACE POINT


// Slack API Token
private $slack_api_token = 'REPLACE_YOUR_API_TOKEN'; // REPLACE POINT
・
・
・
private function setThanksMessage ()
{
    // Slack API(channels.list)でチャンネルの一覧を取得
    $channels = $this->getChannels();
    foreach ($channels as $channel) {
        ・
        ・
        ・
        foreach ($channel_history_messages as $channel_history_message) {
            ・
            ・
            ・
            // 投稿者名と会話している感を出すために吹き出しを追加
            $thanks_message = ':ojigi: <https://REPLACE_YOUR_HOST_NAME.slack.com/archives/'.$channel->name.'/p'.str_replace('.', '', $channel_history_message->ts).'|'.$this->employee_members[$channel_history_message->user]['real_name'].'> ＜'; // REPLACE POINT
            ・
            ・
            ・
        }
    }
}
```

- `app/Console/Kernel.php`

```
protected function schedule(Schedule $schedule)
{
    $schedule->command('thanks_meter')
             ->cron('0 12 * * 1-5'); // CHANGE TO THE TIME YOU WANT TO POST.
}
```
