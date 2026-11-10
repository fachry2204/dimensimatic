export function orderedAlbumTracks<T extends { position?: unknown }>(tracks: T[]): T[] {
    if (tracks.every(track => track.position == null)) return [...tracks];
    const positions = tracks.map(track => Number(track.position));
    if (positions.some(position => !Number.isInteger(position) || position < 1)
        || new Set(positions).size !== tracks.length) {
        throw new Error("METADATA_INVALID:album_track_positions");
    }
    return [...tracks].sort((a, b) => Number(a.position) - Number(b.position));
}

export function assertAlbumOrder(expected: string[], actual: string[]): void {
    // SoundOn repeats a filename in adjacent labels (header and upload
    // summary). Collapse only adjacent copies; a later repeated track or an
    // actual reordering must still fail validation.
    actual = actual.filter((name, index) => index === 0 || name !== actual[index - 1]);
    if (new Set(expected).size !== expected.length
        || expected.length !== actual.length
        || expected.some((filename, index) => filename !== actual[index])) {
        throw new Error(`ASSET_UPLOAD_FAILED:album_order_mismatch:expected=${expected.join('|')}:actual=${actual.join('|')}`);
    }
}
