import {draftOnlyAction} from '../contracts/api.js';
export function assertDraftOnly(action:unknown):asserts action is typeof draftOnlyAction{if(action!==draftOnlyAction)throw new Error('DANGEROUS_ACTION_BLOCKED');}
