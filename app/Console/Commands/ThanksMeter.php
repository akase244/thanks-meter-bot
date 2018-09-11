<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ThanksMeter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'thanks_meter';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Thanks meter';

    // GuzzleHttpのクライアントオブジェクト
    private $guzzleClient;

    // 「ありがとう」の抽出対象
    private $target_messages = [
        'ありがと',
        'アリガト',
        'あざす',
        'アザス',
        'あざます',
        'アザマス',
        'あざっす',
        'アザッス',
        'さんきゅ',
        'サンキュ',
        'さんくす',
        'サンクス',
        'azs',
    ];

    // 全ユーザーの一覧
    private $all_members = [];

    // 社員の一覧
    private $employee_members = [];

    // Slack Webhook URL
    private $slack_webhook_url = 'https://hooks.slack.com/services/REPLACE_YOUR_ACCESS_TOKEN'; // REPLACE POINT

    // Slack API Token
    private $slack_api_token = 'REPLACE_YOUR_API_TOKEN'; // REPLACE POINT

    // ありがとうを含むメッセージの一覧
    private $thanks_messages = [];

    // 投稿文の抽出範囲
    private $range_start_ts;
    private $range_end_ts;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // Guzzleクライアントのオブジェクト
        $this->guzzleClient = new \GuzzleHttp\Client();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // 月曜から金曜日のみ実行
        if ((date('w') == 0 || date('w') == 6)) {
            return;
        }

        // 投稿日時の取得範囲を設定
        $this->setMessagePostRange();

        // 全ユーザー及び社員の一覧を作成
        $this->setMembers();

        // Slack APIからありがとうを含む投稿文を抽出し$this->thanks_messagesにセット
	$this->setThanksMessage();

        // 投稿用のありがとうメッセージを取得
	$thanks_message = $this->getThanksMessage();

        $req = $this->guzzleClient->post($this->slack_webhook_url,[
            'body' => json_encode([
                'channel' => '#counter-bot',
                'username' => 'ありがとうメーター',
                'text' => $thanks_message,
                'icon_emoji' => ':ojigi:',
            ]),
        ]);
    }

    // 投稿内のメンションを復元
    private function replaceToRealName ($message_text)
    {
        preg_match_all('/\<@U\w+?\>/', $message_text, $matches);

        if (isset($matches[0])) {
            foreach ($matches[0] as $match) {
                $replace_pairs = [
                    '<' => '',
                    '@' => '',
                    '>' => '',
                ];
                $member_id = strtr($match, $replace_pairs);
                if (isset($this->all_members[$member_id])) {
                    // 「<@UXXXXXXXXXX>」形式のユーザーIDを「[real_name]」形式に置き換える
                    // nameを利用するとSlack投稿時にメンションとして動作するためreal_nameを利用する
                    $message_text = str_replace($match, '['.$this->all_members[$member_id]['real_name'].']', $message_text);
                }
            }
        }
        return $message_text;
    }

    // 投稿文の中に「ありがとう」を含まない場合はスキップ
    private function hasThanksMessage ($message_text)
    {
        $hasThanksMessage = false;
        foreach ($this->target_messages as $target_message) {
            if (mb_stripos($message_text, $target_message) !== false) {
                $hasThanksMessage = true;
                break;
            }
        }
        return $hasThanksMessage;
    }

    private function setMembers ()
    {
        // Slack API(users.list)でユーザーの一覧を取得
        $user_list_url = 'https://slack.com/api/users.list?token='.$this->slack_api_token.'&pretty=1';
        $user_list_res = $this->guzzleClient->get($user_list_url);
        $user_list_body = json_decode($user_list_res->getBody());

        foreach ($user_list_body->members as $member) {
            // 削除済みユーザーは除外
            if ($member->deleted === false) {
                // 対象を社員のみに絞る
                if (isset($member->is_restricted) && $member->is_restricted === false
                    && isset($member->is_ultra_restricted) &&  $member->is_ultra_restricted === false
                    && isset($member->is_bot) && $member->is_bot === false) {
                    $this->employee_members[$member->id]['name'] = $member->name;
                    $this->employee_members[$member->id]['real_name'] = isset($member->real_name) && $member->real_name ? $member->real_name: $member->name;
                }
                $this->all_members[$member->id]['name'] = $member->name;
                $this->all_members[$member->id]['real_name'] = isset($member->real_name) && $member->real_name ? $member->real_name: $member->name;
            }
        }
    }

    // Slack API(channels.list)でチャンネルの一覧を取得
    private function getChannels ()
    {
        $channel_list_url = 'https://slack.com/api/channels.list?token='.$this->slack_api_token.'&exclude_archived=true&pretty=1';
        $channel_list_res = $this->guzzleClient->get($channel_list_url);
        $channel_list_body = json_decode($channel_list_res->getBody());
        return $channel_list_body->channels;
    }

    private function setThanksMessage ()
    {
        // Slack API(channels.list)でチャンネルの一覧を取得
        $channels = $this->getChannels();
        foreach ($channels as $channel) {
            // クライアントがメンバーとして含まれる可能性があるチャンネル(チャンネル名に「-pub」を含む)はスキップ
            if (strpos($channel->name, '-pub') !== false) {
                continue;
            }

            $channel_id = $channel->id;
            $this->thanks_messages[$channel_id]['channel_name'] = '#'.$channel->name;

            // チャンネルの投稿履歴を取得
            $channel_history_messages = $this->getChannelHistoryMessages($channel_id);
            foreach ($channel_history_messages as $channel_history_message) {
                // 投稿文の妥当性チェック
                if (!$this->isValidMessage($channel_history_message)) {
                    continue;
                }

                // 投稿者名と会話している感を出すために吹き出しを追加
                $thanks_message = ':ojigi: <https://REPLACE_YOUR_HOST_NAME.slack.com/archives/'.$channel->name.'/p'.str_replace('.', '', $channel_history_message->ts).'|'.$this->employee_members[$channel_history_message->user]['real_name'].'> ＜'; // REPLACE POINT

                // 投稿文に改行が含まれている場合は各行がインデントされるように調整
                $changed_message_text = str_replace(PHP_EOL, PHP_EOL.'>', $channel_history_message->text);

                // 投稿内のメンションを復元
                $changed_message_text = $this->replaceToRealName($changed_message_text);

                $thanks_message .= $changed_message_text;
                $this->thanks_messages[$channel_id]['messages'][] = $thanks_message;
            }
        }
    }

    private function getThanksMessage ()
    {
        // ありがとうを含むメッセージのカウント
        $thanks_count = 0;

        $thanks_text  = '';
        foreach ($this->thanks_messages as $thanks_message) {
            if (isset($thanks_message['messages'])) {
                // チャンネル名
                $thanks_text .= '>'.$thanks_message['channel_name'].' ('.count($thanks_message['messages']).'件)'.PHP_EOL;
                // ありがとうを含む投稿文
                $thanks_text .= '>'.implode(PHP_EOL.'>', $thanks_message['messages']).PHP_EOL;
                // チャンネルごとの区切り（改行）
                $thanks_text .= '>'.PHP_EOL;

                $thanks_count += count($thanks_message['messages']);
            }
        }

	$header_text  = date('Y/m/d H:i:s', $this->range_start_ts);
	$header_text .= '〜';
	$header_text .= date('Y/m/d H:i:s', $this->range_end_ts);
	$header_text .= 'の「ありがとう」は'.$thanks_count.'件でした。'.PHP_EOL;
	$message_text = $header_text.$thanks_text;
        return $message_text;
    }

    // 投稿日時の取得範囲を設定
    private function setMessagePostRange ()
    {
        // 前日の00:00:00
        $this->range_start_ts = strtotime('today -1 day');
        // 前日の23:59:59
        $this->range_end_ts = strtotime('today -1 seconds');
        if (date('w') == 1) {
            // 実行日が月曜日の場合は3日前(金曜日)の日付を取得
            // 金曜日の00:00:00
            $this->range_start_ts = strtotime('last Friday');
            // 金曜日の23:59:59
            $this->range_end_ts = strtotime('last Saturday -1 seconds');
        }
    }

    // 投稿文の妥当性チェック
    private function isValidMessage ($message)
    {
        // 前日の00:00:00〜前日の23:59:59の範囲外の場合はスキップ
        if ($message->ts < $this->range_start_ts || $message->ts > $this->range_end_ts) {
            return false;
        }

        // 投稿文が未定義の場合はスキップ
        if (!isset($message->text)) {
            return false;
        }

        // 投稿文の中に「ありがとう」を含まない場合はスキップ
        if (!$this->hasThanksMessage($message->text)) {
            return false;
        }

        // 社員以外の投稿文はスキップ
        if (!(isset($message->user) && isset($this->employee_members[$message->user]['name']))) {
            return false;
        }

        return true;
    }

    // チャンネルの投稿履歴を取得
    private function getChannelHistoryMessages ($channel_id)
    {
        // Slack API(channels.history)でチャンネルの投稿履歴を取得
        $channel_history_url = 'https://slack.com/api/channels.history?token='.$this->slack_api_token.'&channel='.$channel_id.'&pretty=1';
        $channel_history_res = $this->guzzleClient->get($channel_history_url);
        $channel_history_body = json_decode($channel_history_res->getBody());
        return $channel_history_body->messages;
    }
}
