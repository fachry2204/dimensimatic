import {test,expect,vi} from 'vitest';
import {randomUUID} from 'node:crypto';
import {startPublishProgress,readPublishProgress,getPublishProgress} from './publish-progress.js';
test('isolates concurrent releases, caps SoundOn stage, and never regresses',()=>{
 const first=randomUUID(),second=randomUUID();
 const report=startPublishProgress(first);startPublishProgress(second);
 report(55,'Next 3');report(25,'Retry search');
 expect(getPublishProgress(first)?.percent).toBe(55);
 expect(getPublishProgress(second)?.percent).toBe(10);
 report(100,'SoundOn confirmed');
 expect(readPublishProgress([first])[first]?.percent).toBe(85);
 expect(Object.keys(readPublishProgress([first]))).toEqual([first]);
});
test('rejects invalid IDs and omits expired attempts',()=>{
 expect(()=>startPublishProgress('invalid')).toThrow('INVALID_PROGRESS_ID');
 vi.useFakeTimers();try {
  const id=randomUUID();startPublishProgress(id);
  vi.advanceTimersByTime(3601000);
  expect(readPublishProgress([id])).toEqual({});
 }finally{vi.useRealTimers();}
});
