<?php

namespace Tests\Feature;

use App\Jobs\CheckSoundOnReleaseStatus;
use App\Jobs\QueueSoundOnStatusChecks;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StatusCollectionProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_background_collection_exposes_date_progress_and_then_queues_checks(): void
    {
        Queue::fake();
        AutomationAccount::create(['platform'=>'soundfresh','name'=>'CMS','status'=>'active','last_authenticated_at'=>now(),'session_state_encrypted'=>['cookies'=>[]]]);
        $run=AutomationRun::create(['status'=>'queued','summary_json'=>['purpose'=>'soundon_status_check']]);
        $polls=0; $collectionId=null;
        Http::fake(function ($request) use (&$polls,&$collectionId,$run) {
            if(str_ends_with($request->url(),'/pending')) {
                $this->assertTrue($request['options']['async_collection']);
                $this->assertSame('uploading',$request['options']['release_status']);
                $collectionId=$request['collection_id'];
                return Http::response(['data'=>['collection_id'=>$collectionId]]);
            }
            $this->assertSame($collectionId,$request['collection_id']);
            if(++$polls===1) return Http::response(['data'=>['status'=>'running','message'=>'Membaca tanggal rilis CMS 1 / 4','date_completed'=>1,'date_total'=>4,'updatedAt'=>1]]);
            $this->assertSame('Membaca tanggal rilis CMS 1 / 4',data_get($run->fresh()->summary_json,'collection_progress.message'));
            $this->assertNotNull(data_get($run->fresh()->summary_json,'heartbeat_at'));
            return Http::response(['data'=>['status'=>'completed','message'=>'Selesai membaca CMS','items'=>[[
                'release_id'=>'3945','detail_url'=>'https://dimensisuara.id/admin/releases/3945',
                'title'=>'Sunshine Through the Windows','primary_artist'=>'Jason Edward Coleman','track_count'=>1,'release_date'=>'2026-12-04',
            ]]]]);
        });
        app()->call([new QueueSoundOnStatusChecks(null,'uploading',$run->id),'handle']);
        $this->assertSame('checking',data_get($run->fresh()->summary_json,'phase'));
        $this->assertSame('2026-12-04',data_get(ReleaseJob::first()->metadata_snapshot_json,'release_date'));
        Queue::assertPushed(CheckSoundOnReleaseStatus::class,1);
        Http::assertSentCount(3);
    }

    public function test_background_failure_unlocks_run_with_the_actual_collection_error(): void
    {
        Queue::fake();
        AutomationAccount::create(['platform'=>'soundfresh','name'=>'CMS','status'=>'active','last_authenticated_at'=>now(),'session_state_encrypted'=>['cookies'=>[]]]);
        $run=AutomationRun::create(['status'=>'queued','summary_json'=>['purpose'=>'soundon_status_check']]);
        Http::fake(fn($request)=>Http::response(['data'=>str_ends_with($request->url(),'/pending')
            ? ['collection_id'=>$request['collection_id']]
            : ['status'=>'failed','error'=>'AUTH_SESSION_EXPIRED: Login CMS diperlukan']]));
        app()->call([new QueueSoundOnStatusChecks(null,'uploading',$run->id),'handle']);
        $this->assertSame('failed',$run->fresh()->status->value);
        $this->assertStringContainsString('AUTH_SESSION_EXPIRED',data_get($run->fresh()->summary_json,'error'));
        Queue::assertNothingPushed();
    }

    public function test_invalid_collection_receipt_fails_without_polling_forever(): void
    {
        Http::fake(['*'=>Http::response(['data'=>['collection_id'=>'wrong-id']])]);
        $this->expectExceptionMessage('CMS_COLLECTION_NOT_STARTED');
        app(PlaywrightClient::class)->uploading(null,null,fn($progress)=>null);
    }
}
