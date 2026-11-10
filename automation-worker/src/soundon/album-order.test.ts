import { describe, expect, it } from 'vitest';
import { assertAlbumOrder, orderedAlbumTracks } from './album-order.js';

describe('album order', () => {
    it('accepts adjacent header/summary labels observed in SoundOn', () => {
        expect(() => assertAlbumOrder(['1', '2', '3'], ['1', '1', '2', '2', '3', '3'])).not.toThrow();
        expect(() => assertAlbumOrder(['1', '2'], ['1', '2', '1'])).toThrow();
    });
    it('uses numeric Soundfresh positions while preserving each audio/clip pair', () => {
        const tracks = [10, 2, 1].map(position => ({ position, audio: `${position}.wav`, clip: `${position}-clip.wav` }));
        expect(orderedAlbumTracks(tracks).map(t => [t.audio, t.clip])).toEqual([
            ['1.wav', '1-clip.wav'], ['2.wav', '2-clip.wav'], ['10.wav', '10-clip.wav'],
        ]);
        expect(tracks[0]!.position).toBe(10);
    });
    it('rejects ambiguous positions and duplicate filenames', () => {
        expect(() => orderedAlbumTracks([{position: 1}, {position: 1}])).toThrow();
        expect(() => orderedAlbumTracks([{position: 1}, {}])).toThrow();
        expect(() => assertAlbumOrder(['a', 'a'], ['a', 'a'])).toThrow();
    });
    it('rejects completion/lexical order different from source order', () => {
        expect(() => assertAlbumOrder(['1', '2', '10'], ['1', '10', '2'])).toThrow();
        expect(() => assertAlbumOrder(['1', '2'], ['1'])).toThrow();
        expect(() => assertAlbumOrder(['1', '2'], ['1', '2'])).not.toThrow();
    });
});
