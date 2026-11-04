<?php
declare(strict_types=1);
$tracker = !is_file(__DIR__ . '/../functions/runemetrics.php');
require_once __DIR__ . ($tracker ? '/../api/functions/runemetrics.php' : '/../functions/runemetrics.php');
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function fixture(string $name): array {
    return json_decode(file_get_contents(__DIR__ . '/fixtures/platform/' . $name),true,512,JSON_THROW_ON_ERROR);
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}
$profile = fixture('Current_RuneMetrics_Profile.json');
$events = fixture('New_Player_Events.json');
$hiscores = fixture('New_HighScores.json');
$quests = fixture('New_Player_Quests.json');
$oldQuests = fixture('Current_RuneMetrics_Quests.json');
foreach (['events'=>$events,'hiscores'=>$hiscores,'quests'=>$quests] as $source=>$data) rp_validate_payload($source,$data,'lodo');
check(str_ends_with(rp_url('events','Some Name'),'/Some_Name'),'Path encodes player name');
check(rp_normalise_name("Lodo\u{200B}")===rp_normalise_name('lodo'),'Invisible display-name characters do not change identity');
$normal = rp_hiscores($hiscores); $snapshot = $normal['snapshot'];
$skills = json_decode($snapshot['skills_json'],true);
check($snapshot['total_xp']===1859856013 && $skills['total']['level']===3170,'Overall units/level');
check($skills['Attack']['xp']===29986847,'Hiscores XP is not divided by ten');
check(isset($skills['Constitution'],$skills['Runecrafting']) && !isset($skills['Hitpoints'],$skills['Runecraft']),'Preserve application skill aliases');
$legacy = rm_parse_xp_snapshot_div10($profile);
check($legacy['total_xp']-$snapshot['total_xp']===18768,'Fixture exposes cross-source XP lag');
check($normal['summary']['runescore']['score']===34450,'RuneScore mapping');
check($normal['summary']['clues_total_from_tiers']===37 && $normal['summary']['clues']['total']['score']===37,'Clue totals exclude Leagues');
check($normal['misc']['gielinor_games_resource_race']['label']==='GG: Resource Race','GG labels follow names');
$shuffled=$hiscores; $shuffled['skills']=array_reverse($shuffled['skills']); $shuffled['activities']=array_reverse($shuffled['activities']);
foreach($shuffled['activities'] as &$activity) $activity['id']+=100; unset($activity);
check(rp_hiscores($shuffled)['snapshot']===$snapshot,'Skill order is immaterial');
check(rp_hiscores($shuffled)['summary']['clues_total_from_tiers']===37,'Activity positions/IDs do not affect mappings');
$partial=$hiscores; $partial['skills'][1]['xp']=-1;
check(rp_hiscores($partial)['snapshot']===null,'Missing XP cannot produce a zero snapshot');
$partial=$hiscores;
foreach($partial['activities'] as &$a) if($a['name']==='Clue Scrolls (easy)') $a['score']=-1; unset($a);
check(rp_hiscores($partial)['summary']['clues_total_from_tiers']===null,'Incomplete clue totals remain unknown');
check($normal['misc']['conquest']['score']===1000 && !$normal['misc']['conquest']['ranked'],'Unranked nonzero scores remain available');
$q=rp_quests($quests);
check($q['totals']===['total'=>366,'completed'=>361,'started'=>1,'not_started'=>4,'quest_points_completed'=>476,'quest_points_max'=>476],'Authoritative QP and status counts');
$oldByTitle=array_column($oldQuests['quests'],null,'title');
foreach($q['quests'] as $quest) {
    $old=$oldByTitle[$quest['title']]; unset($quest['id']);
    ksort($quest); ksort($old); check($quest===$old,'Quest compatibility fields: '.$quest['title']);
}
foreach($events['events'] as $i=>$event) {
    check($event['text']===$profile['activities'][$i]['text'] && $event['details']===$profile['activities'][$i]['details'],'Event content parity');
    check(rp_event_date($event['date'])->format('Y-m-d H:i')===rm_parse_activity_date_utc($profile['activities'][$i]['date'])->format('Y-m-d H:i'),'Legacy event minute parity');
}
check(rp_event_date('2026-07-01T12:30:45+01:00')->format('H:i:s')==='11:30:45','Summer offset converted once');
rejects(fn()=>rp_event_date('2026-07-01 12:30:45'),'Reject missing timezone');
rejects(fn()=>rp_event_date('2026-02-30T12:30:45Z'),'Reject impossible dates');
rejects(fn()=>rp_validate_payload('events',$events,'Another'),'Reject another character');
$empty=$events; $empty['events']=[]; rp_validate_payload('events',$empty,'Lodo');
foreach([['status'=>404,'body'=>'{"error":"PLAYER_NOT_FOUND"}'],['status'=>403,'body'=>'{"error":"PLAYER_NOT_MEMBER"}'],
    ['status'=>500,'body'=>'{"error":"INTERNAL_ERROR"}'],['status'=>200,'body'=>'<html>unavailable</html>'],
    ['status'=>200,'body'=>'{}']] as $response) check(!rp_request('events','Lodo',fn()=>$response)['ok'],'Structured and malformed failures are not successes');
$limit=rp_request('events','Lodo',fn()=>['status'=>429,'body'=>'{}','retry_after'=>1200]);
check(!$limit['ok'] && $limit['retry_after']===1200,'Retry-After preserved');
foreach([false,true] as $cap) check(rp_poll_schedule(['ok'=>true,'cap_detected'=>$cap],1000)['next_poll']===1300,'Capping keeps normal five-minute interval');
check(rp_poll_schedule(['ok'=>false,'sources'=>['events'=>['processed_ok'=>true],'hiscores'=>['http_code'=>429]]],1000)['next_poll']===1300,'One failed source does not delay successful source');
check(rp_poll_schedule(['ok'=>false,'sources'=>['events'=>['http_code'=>429,'retry_at_utc'=>'1970-01-01 01:00:00']]],1000)['next_poll']===3600,'Rate-limit schedule respects Retry-After');
echo "Platform fixture/adapter/scheduling checks passed.\n";

$dsn=getenv('PLATFORM_TEST_DSN');
if(!$dsn) { echo "Database checks skipped (set PLATFORM_TEST_DSN).\n"; exit; }
require_once __DIR__ . ($tracker ? '/../db_bootstrap.php' : '/../setup/db_bootstrap.php');
$pdo=new PDO($dsn,getenv('PLATFORM_TEST_USER')?:'root',getenv('PLATFORM_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='platform_test_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$db`"); $pdo->exec("USE `$db`");
try {
    dbb_create_tables($pdo);
    $pdo->exec("INSERT INTO clans(name,reset_weekday) VALUES('Test clan',1)");
    $pdo->exec("INSERT INTO members(clan_id,rsn,rsn_normalised,is_private) VALUES(1,'Lodo','lodo',1)");
    $pdo->exec("INSERT INTO member_poll_state(member_id,clan_id,next_poll_at_utc,last_http_status) VALUES(1,1,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR),200)");
    rp_ensure_schema($pdo); rp_ensure_schema($pdo);
    check((int)$pdo->query('SELECT is_private FROM members WHERE id=1')->fetchColumn()===0,'Migration clears obsolete privacy state');
    check((int)$pdo->query('SELECT next_poll_at_utc <= UTC_TIMESTAMP(3) FROM member_poll_state WHERE member_id=1')->fetchColumn()===1,'Migration releases old post-cap/private wait');
    $member=$pdo->query('SELECT * FROM members WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $cap=false; $week=null;
    check(rm_db_insert_activities_with_processing($pdo,1,1,$profile['activities'],$cap,$week)===20,'Seed real legacy events');
    $pdo->exec('UPDATE member_activities SET is_announced=1, announced_at=UTC_TIMESTAMP(3)');
    $before=$pdo->query('SELECT id,activity_hash,activity_date_utc,is_announced,announced_at FROM member_activities ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    check(rm_db_insert_activities_with_processing($pdo,1,1,$events['events'],$cap,$week)===0,'New events reconcile all twenty legacy records');
    check($before===$pdo->query('SELECT id,activity_hash,activity_date_utc,is_announced,announced_at FROM member_activities ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Reconciliation preserves IDs, chronology and announcement state');
    check((int)$pdo->query('SELECT COUNT(*) FROM member_activities WHERE platform_event_hash IS NOT NULL AND platform_event_type IS NOT NULL')->fetchColumn()===20,'Store exact event identity/type');
    check(rm_db_insert_activities_with_processing($pdo,1,1,$events['events'],$cap,$week)===0,'Platform replay is idempotent');
    $pdo->exec("INSERT INTO members(clan_id,rsn,rsn_normalised) VALUES(1,'Remapped','remapped')");
    $pdo->exec('INSERT INTO member_activities (member_id,member_clan_id,activity_hash,activity_date_utc,activity_text,activity_details,is_announced,platform_event_hash,platform_event_type,platform_event_date_utc)
        SELECT 2,member_clan_id,activity_hash,activity_date_utc,activity_text,activity_details,is_announced,platform_event_hash,platform_event_type,platform_event_date_utc
        FROM member_activities WHERE member_id=1 ORDER BY id LIMIT 1');
    check(rm_db_insert_activities_with_processing($pdo,2,1,[$events['events'][0]],$cap,$week)===0,'Remapped precise event reuses historical row despite original member hash');
    $newEvent=['date'=>'2026-11-05T12:00:01Z','text'=>'A new event','details'=>'Example','type'=>'rs_plog_special_event'];
    $another=$newEvent; $another['date']='2026-11-05T12:00:02Z';
    check(rm_db_insert_activities_with_processing($pdo,1,1,[$newEvent,$another],$cap,$week)===2,'Distinct same-minute events remain distinct');
    $ambiguous=['date'=>'05-Nov-2026 13:00','text'=>'Repeated','details'=>'Same text'];
    rm_db_insert_activities_with_processing($pdo,1,1,[$ambiguous],$cap,$week);
    $a=$ambiguous; $a['type']='rs_plog_special_event'; $a['date']='2026-11-05T13:00:00Z';
    $b=$a; $b['date']='2026-11-05T13:00:30Z';
    rejects(function()use($pdo,$a,$b,&$cap,&$week){rm_db_insert_activities_with_processing($pdo,1,1,[$a,$b],$cap,$week);},'Ambiguous overlap must not duplicate or silently merge events');
    $pdo->exec("INSERT INTO activity_announcement_rules(purpose,match_kind,match_value) VALUES('cap_detection','text_equals','Capped'),('visit_detection','text_equals','Visited')");
    $citadel=[['date'=>'2026-11-05T14:00:10Z','text'=>'Capped','details'=>'Cap test','type'=>'rs_plog_special_event'],
        ['date'=>'2026-11-05T14:00:20Z','text'=>'Visited','details'=>'Visit test','type'=>'rs_plog_special_event']];
    rm_db_insert_activities_with_processing($pdo,1,1,$citadel,$cap,$week);
    check($cap && (int)$pdo->query('SELECT COUNT(*) FROM member_caps')->fetchColumn()===1,'Typed cap detected');
    check((int)$pdo->query('SELECT COUNT(*) FROM member_citadel_visits')->fetchColumn()===1,'Typed visit detected');
    check(rp_poll_schedule(['ok'=>true,'cap_detected'=>$cap],1000)['next_poll']===1300,'Detected cap keeps normal interval');

    $calls=[];
    $transport=function(string $url)use(&$calls,$events,$hiscores){$calls[]=$url;return ['status'=>200,'body'=>json_encode(str_contains($url,'playerEvents')?$events:$hiscores)];};
    $result=rp_sync_data($pdo,$member,$transport);
    check($result['ok'] && $result['xp_snapshot_inserted'],'Both sources sync');
    $result=rp_sync_data($pdo,$member,$transport);
    check($result['ok'] && !$result['xp_snapshot_inserted'] && count($calls)===2,'Unchanged XP uses shared fetch cache without duplicate snapshots');
    check(!rp_sync_due(rp_fetch_states($pdo,1)),'Fetch freshness does not depend on new XP');
    rm_db_insert_xp_snapshot($pdo,1,$legacy['total_xp'],$legacy['skills_json'],$legacy['snapshot_hash']);
    // Give the historical fixture deterministic ordering after the earlier snapshot.
    $pdo->exec("UPDATE member_xp_snapshots SET captured_at_utc=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE total_xp=1859874781");
    $result=rp_sync_data($pdo,$member,$transport);
    check(!$result['ok'] && str_contains($result['error'],'HISCORES_BEHIND') && !empty($result['sources']['events']['processed_ok']),'Stale Hiscores cannot roll back current XP; events continue');
    check(str_contains(rp_fetch_states($pdo,1)['hiscores']['processing_error'],'HISCORES_BEHIND'),'Processing failure visible separately from fetch freshness');
    $pdo->exec('DELETE FROM member_xp_snapshots'); $pdo->exec('DELETE FROM member_platform_fetches');
    $transport=function(string $url)use($hiscores){return str_contains($url,'playerEvents')?['status'=>500,'body'=>'{"error":"INTERNAL_ERROR"}']:['status'=>200,'body'=>json_encode($hiscores)];};
    $result=rp_sync_data($pdo,$member,$transport);
    check(!$result['ok'] && $result['xp_snapshot_inserted'],'Events failure does not block XP');
    $pdo->exec('DELETE FROM member_platform_fetches');
    $transport=function(string $url)use($events){return str_contains($url,'playerEvents')?['status'=>200,'body'=>json_encode($events)]:['status'=>429,'body'=>'{}','retry_after'=>900];};
    $result=rp_sync_data($pdo,$member,$transport);
    check(!$result['ok'] && !empty($result['sources']['events']['processed_ok']),'Hiscores failure does not block events');
    $callCount=0;
    $limited=rp_cached_fetch($pdo,1,'hiscores','Lodo',300,function()use(&$callCount){$callCount++;throw new RuntimeException('Should use negative cache');});
    check(!$limited['ok'] && $callCount===0 && $limited['http_code']===429,'Respect provider backoff across callers');
    $pdo->exec('DELETE FROM member_platform_fetches');
    $transport=function(string $url)use($hiscores){return str_contains($url,'playerEvents')?['status'=>403,'body'=>'{"error":"PLAYER_NOT_MEMBER"}']:['status'=>200,'body'=>json_encode($hiscores)];};
    $result=rp_sync_data($pdo,$member,$transport);
    check($result['ok'] && $result['status']==='source_unavailable' && (int)$pdo->query('SELECT is_private FROM members WHERE id=1')->fetchColumn()===0,'Non-membership does not become legacy private status');
    $pdo->exec("UPDATE member_platform_fetches SET fetched_at_utc='2000-01-01' WHERE source='hiscores'");
    $failed=rp_cached_fetch($pdo,1,'hiscores','Lodo',300,fn()=>['status'=>500,'body'=>'{}']);
    check(!$failed['ok'] && $failed['data']['skills'][0]['xp']===1859856013,'Failed refresh retains last good raw payload');
    $renamed=rp_cached_fetch($pdo,1,'hiscores','Other',300,fn()=>['status'=>404,'body'=>'{"error":"PLAYER_NOT_FOUND"}']);
    check($renamed['data']===null,'Renames cannot reuse another identity cache');
    if ($tracker) {
        require_once __DIR__ . '/../api/functions/player_platform_cache.php';
        $questTransport=fn()=>['status'=>200,'body'=>json_encode($quests)];
        $view=tracker_get_platform_cache($pdo,1,1,'Lodo','quests',true,$questTransport);
        check($view['ok'] && $view['totals']['quest_points_completed']===476 && count($view['quests'])===366,'Player cache preserves UI shape and authoritative QP');
        check($view['quests'][0]['id']>0 && isset($view['quests'][0]['questPoints']),'Player quest IDs and existing field names');
        $calls=0;
        $cached=tracker_get_platform_cache($pdo,1,1,'Lodo','quests',true,function()use(&$calls){$calls++;throw new RuntimeException('No fetch expected');});
        check($cached['cache']['hit'] && $calls===0,'Fresh normalized cache avoids fetching');
        $pdo->exec("UPDATE member_rm_quests SET updated_at='2000-01-01'");
        $pdo->exec("UPDATE member_platform_fetches SET fetched_at_utc='2000-01-01' WHERE source='quests'");
        $failed=tracker_get_platform_cache($pdo,1,1,'Lodo','quests',true,fn()=>['status'=>500,'body'=>'{"error":"INTERNAL_ERROR"}']);
        check($failed['ok'] && $failed['cache']['stale'] && $failed['totals']['quest_points_completed']===476,'Quest failure keeps last good UI data');
        check(tracker_read_json_cache($pdo,'rm_quests',1,1)['data']['error']===null,'Failed quest response never poisons normalized cache');
        $view=tracker_get_platform_cache($pdo,1,1,'Lodo','hiscores',true,fn()=>['status'=>200,'body'=>json_encode($hiscores)]);
        check($view['summary']['clues_total_from_tiers']===37,'Hiscores UI cache keeps normalized summary');
        $pdo->exec("UPDATE member_hiscores_lite SET json_data=JSON_SET(json_data,'$.schema_version',1,'$.summary.clues_total_from_tiers',0)");
        $view=tracker_get_platform_cache($pdo,1,1,'Lodo','hiscores',true,fn()=>throw new RuntimeException('Use shared raw cache'));
        check($view['schema_version']===2 && $view['summary']['clues_total_from_tiers']===37,'Old normalized cache version is rebuilt from shared raw data');
    }
    echo "Platform database migration/replay/cache/partial-failure checks passed.\n";
} finally { $pdo->exec("DROP DATABASE `$db`"); }
