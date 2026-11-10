export type StorageState={cookies:Array<Record<string,unknown>>;origins:Array<Record<string,unknown>>};
export type SourceAsset={url:string;filename:string;worker_local_path?:string};
export type TrackMetadata={title:string;primary_artist:string;audio:SourceAsset;isrc?:string;language?:string;explicit?:boolean;instrumental?:boolean};
export type ReleaseMetadata={title:string;primary_artist:string;release_type:'single'|'ep'|'album';genre:string;language?:string;release_date:string;pre_release_date?:string;cover:SourceAsset;tracks:TrackMetadata[]};
export type WorkerResponse<T>={success:true;data:T}|{success:false;error:string;message?:string};
export const draftOnlyAction='save_draft' as const;
