import {describe,expect,it} from 'vitest';import {assertDraftOnly} from './draft-only.js';
describe('draft-only guard',()=>{it('allows save_draft',()=>expect(()=>assertDraftOnly('save_draft')).not.toThrow());it.each(['submit','publish','distribute','send_for_review'] as const)('blocks %s',(action)=>expect(()=>assertDraftOnly(action)).toThrow('DANGEROUS_ACTION_BLOCKED'));});
