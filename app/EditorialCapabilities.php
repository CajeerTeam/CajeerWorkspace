<?php
declare(strict_types=1);
namespace Workspace;

/** Exact adapter contracts. Unknown versions fail closed; this is not remote discovery. */
const EDITORIAL_ADAPTERS = [
    'engine'=>['2.1.0'=>['profile'=>'engine-content-v1','capabilities'=>['read','revisions','publish_cas','reconcile']]],
    'book'=>['1.2.0'=>['profile'=>'book-reader-v1','capabilities'=>['read','snapshot','compare']]],
    'lite'=>['1.6.0'=>['profile'=>'lite-native-schema14','schema'=>14,'capabilities'=>['inspect','publish_locked','reconcile','restore_offline']]],
    'forum'=>['1.6.0'=>['profile'=>'forum-api-v1','core'=>'2.1.0','api'=>'1.1','capabilities'=>['personal_acl','read','create','moderation','recover_exact']]],
];
function editorialCapability(string $kind,mixed $version,string $operation): array {
    $p=is_string($version)?(EDITORIAL_ADAPTERS[$kind][$version]??null):null;
    if(!$p||!in_array($operation,$p['capabilities'],true))throw new Problem('Несовместимая версия или возможность редакторского адаптера.',409);
    return ['kind'=>$kind,'version'=>$version]+$p;
}
function editorialForumHealth(array $health): void {
    $p=editorialCapability('forum',$health['edition_version']??null,'personal_acl');
    if(($health['version']??null)!==$p['core']||($health['engine_api_version']??null)!==$p['api'])throw new Problem('Несовместимое ядро или API Forum.',409);
}
/** Exact byte hashes plus bounded readable comparison; line endings are not normalized. */
function editorialTextDiff(string $before,string $after): array {
    $a=explode("\n",$before);$b=explode("\n",$after);$n=count($a);$m=count($b);$prefix=0;$suffix=0;
    while($prefix<min($n,$m)&&$a[$prefix]===$b[$prefix])++$prefix;
    while($suffix<min($n,$m)-$prefix&&$a[$n-1-$suffix]===$b[$m-1-$suffix])++$suffix;
    $old=$n-$prefix-$suffix;$new=$m-$prefix-$suffix;
    // Never allocate an unbounded quadratic diff matrix; display the exact changed range.
    $excerpt=static function(array $lines,int $start,int $count): string {
        $s=implode("\n",array_slice($lines,$start,min(80,$count)));
        if(strlen($s)>16000){$s=substr($s,0,16000);while($s!==''&&!preg_match('//u',$s))$s=substr($s,0,-1);}return $s;
    };
    return ['equal'=>$before===$after,'before_hash'=>hash('sha256',$before),'after_hash'=>hash('sha256',$after),'first_line'=>$prefix+1,'removed'=>$old,'added'=>$new,'before'=>$excerpt($a,$prefix,$old),'after'=>$excerpt($b,$prefix,$new),'bounded'=>$old>80||$new>80||strlen(implode("\n",array_slice($a,$prefix,min(80,$old))))>16000||strlen(implode("\n",array_slice($b,$prefix,min(80,$new))))>16000];
}
/** Exact identity and first post, never infer from title alone. */
function editorialThreadMatches(array $thread,string $title,string $body,string $actor): bool {
    return ($thread['thread']['title']??null)===$title&&is_string($thread['first_post_id']??null)&&($thread['posts'][0]['id']??null)===$thread['first_post_id']&&($thread['posts'][0]['body']??null)===$body&&($thread['posts'][0]['author_id']??null)===$actor&&empty($thread['posts'][0]['ignored_author']);
}
function editorialLiteObservation(array $job,array $site,array $page,int $now): bool {
    if($job['state']!=='published'||!is_string($job['after_hash']??null)||!is_string($job['backup_hash']??null)||$job['after_hash']!==($site['site_hash']??null)||!is_string($site['catalog']??null))return false;
    $age=$now-(int)$site['inspected_at'];if($age<0||$age>900)return false;
    $row=json_decode($site['catalog'],true,64,JSON_THROW_ON_ERROR)[$job['path']]??null;
    return is_array($row)&&($row['published']??null)===true&&($row['title']??null)===$page['title']&&($row['body']??null)===$page['body'];
}
/** An interrupted native job may be declared aborted only if original bytes are observed. */
function editorialLiteRecovery(array $job,string $hash): string {
    if(hash_equals($job['expected_hash'],$hash))return 'aborted';
    if(is_string($job['backup_name']??null)&&is_string($job['backup_hash']??null)&&is_string($job['after_hash']??null)&&hash_equals($job['after_hash'],$hash))return 'published';
    return 'uncertain';
}
