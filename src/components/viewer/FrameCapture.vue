<template>
  <div class="frame-capture" @pointerdown.stop @touchstart.stop @wheel.stop>
    <div class="top">
      <NcButton variant="tertiary-no-background" :aria-label="t('memories', 'Close')" @click="close">
        <template #icon>
          <CloseIcon :size="24" />
        </template>
      </NcButton>
      <span class="title">{{ t('memories', 'Save frame') }}</span>
      <NcButton variant="primary" :disabled="saving" @click="save">
        {{ t('memories', 'Save') }}
      </NcButton>
    </div>

    <div class="bottom">
      <div class="time">{{ timeStr }}</div>
      <div class="controls">
        <NcButton variant="tertiary-no-background" :aria-label="t('memories', 'Previous frame')" @click="step(-1)">
          <template #icon>
            <ChevronLeftIcon :size="28" />
          </template>
        </NcButton>

        <div class="strip-outer">
          <div ref="strip" class="strip" @scroll.passive="onScroll" @wheel.prevent="onWheel">
            <div class="track" :style="{ width: `${duration * pxPerSec}px`, margin: `0 ${stripWidth / 2}px` }">
              <div
                v-for="(cue, i) of cues"
                :key="i"
                class="cue"
                :style="{ left: `${cue.start * pxPerSec}px`, width: `${(cue.end - cue.start) * pxPerSec}px` }"
              >
                <div
                  v-for="j of Math.ceil(((cue.end - cue.start) * pxPerSec) / (cue.w * thumbScale(cue)))"
                  :key="j"
                  class="thumb"
                  :style="{ width: `${cue.w * thumbScale(cue)}px` }"
                >
                  <div
                    class="sprite"
                    :style="{
                      width: `${cue.w}px`,
                      height: `${cue.h}px`,
                      backgroundImage: `url(${cue.url})`,
                      backgroundPosition: `-${cue.x}px -${cue.y}px`,
                      transform: `scale(${thumbScale(cue)})`,
                    }"
                  ></div>
                </div>
              </div>
            </div>
          </div>
          <div class="cursor"></div>
        </div>

        <NcButton variant="tertiary-no-background" :aria-label="t('memories', 'Next frame')" @click="step(1)">
          <template #icon>
            <ChevronRightIcon :size="28" />
          </template>
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { API } from '@services/API';
import * as dav from '@services/dav';
import * as utils from '@services/utils';
import staticConfig from '@services/static-config';

import type { IPhoto } from '@typings';
import type { MediaPlayerElement } from 'vidstack/elements';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue';

type Cue = { start: number; end: number; url: string; x: number; y: number; w: number; h: number };

const STRIP_HEIGHT = 48;
const DEFAULT_FPS = 30;

export default defineComponent({
  name: 'FrameCapture',
  components: {
    NcButton,
    CloseIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
  },

  props: {
    photo: {
      type: Object as PropType<IPhoto>,
      required: true,
    },
    player: {
      type: Object as PropType<MediaPlayerElement>,
      required: true,
    },
  },

  emits: {
    close: () => true,
  },

  data: () => ({
    time: 0,
    duration: 0,
    /** Media time of the frame on screen, if the browser reports it */
    frameTime: null as number | null,
    stripWidth: 0,
    cues: [] as Cue[],
    saving: false,
    seeking: false,
    pendingSeek: null as number | null,
    frameCallback: 0,
  }),

  computed: {
    fps(): number {
      return this.photo.imageInfo?.exif?.FrameRate || DEFAULT_FPS;
    },

    /** Long videos follow the storyboard density, short ones span two strip widths */
    pxPerSec(): number {
      const cue = this.cues[0];
      const sb = cue ? (cue.w * this.thumbScale(cue)) / (cue.end - cue.start) : 0;
      return Math.max(sb, (2 * this.stripWidth) / (this.duration || 1));
    },

    timeStr(): string {
      const ms = Math.round(this.time * 1000);
      const min = Math.floor(ms / 60000);
      const sec = String(Math.floor((ms % 60000) / 1000)).padStart(2, '0');
      return `${min}:${sec}.${String(ms % 1000).padStart(3, '0')}`;
    },
  },

  mounted() {
    void this.player.pause()?.catch(() => undefined);
    this.time = this.player.currentTime;
    this.duration = this.player.duration || 0;
    this.stripWidth = this.refs().strip?.clientWidth ?? 0;
    this.$nextTick(() => this.scrollToTime());

    this.player.addEventListener('seeked', this.onSeeked);
    this.player.addEventListener('duration-change', this.onDurationChange);
    window.addEventListener('keydown', this.onKeydown, true);
    window.addEventListener('resize', this.onResize);
    this.watchFrames();
    this.loadStoryboard();

    utils.fragment.push(utils.fragment.types.editor);
    utils.bus.on('memories:fragment:pop:editor', this.close);
  },

  beforeUnmount() {
    this.player.removeEventListener('seeked', this.onSeeked);
    this.player.removeEventListener('duration-change', this.onDurationChange);
    window.removeEventListener('keydown', this.onKeydown, true);
    window.removeEventListener('resize', this.onResize);
    this.getVideo()?.cancelVideoFrameCallback?.(this.frameCallback);

    utils.bus.off('memories:fragment:pop:editor', this.close);
    utils.fragment.pop(utils.fragment.types.editor);
  },

  methods: {
    refs() {
      return this.$refs as { strip?: HTMLDivElement };
    },

    close() {
      this.$emit('close');
    },

    /** Provider element, which may be swapped on HLS failover */
    getVideo(): HTMLVideoElement | null {
      return this.player.querySelector('video');
    },

    thumbScale(cue: Cue): number {
      return STRIP_HEIGHT / cue.h;
    },

    /** Storyboard sprites from go-vod as strip thumbnails */
    async loadStoryboard() {
      if (staticConfig.getSync('vod_disable')) return;

      try {
        const vtt = new URL(API.VIDEO_STORYBOARD(this.photo.fileid, 'storyboard.vtt'), window.location.href);
        const res = await axios.get<string>(vtt.href, { responseType: 'text' });
        const re = /([\d:.]+) --> ([\d:.]+)\n(\S+)#xywh=(\d+),(\d+),(\d+),(\d+)/g;
        this.cues = Array.from(res.data.matchAll(re), (m) => ({
          start: this.parseVttTime(m[1]),
          end: this.parseVttTime(m[2]),
          url: new URL(m[3], vtt).href,
          x: Number(m[4]),
          y: Number(m[5]),
          w: Number(m[6]),
          h: Number(m[7]),
        }));
        this.$nextTick(() => this.scrollToTime());
      } catch (e) {
        console.warn('FrameCapture: no storyboard', e);
      }
    },

    parseVttTime(s: string): number {
      return s.split(':').reduce((acc, part) => acc * 60 + Number(part), 0);
    },

    /** Track the media time of each presented frame */
    watchFrames() {
      const video = this.getVideo();
      if (!video?.requestVideoFrameCallback) return;
      this.frameCallback = video.requestVideoFrameCallback((_, meta) => {
        this.frameTime = meta.mediaTime;
        this.watchFrames();
      });
    },

    onScroll() {
      const strip = this.refs().strip;
      if (!strip || !this.duration) return;

      // Ignore scroll events caused by scrollToTime
      if (Math.abs(strip.scrollLeft - this.time * this.pxPerSec) < 1) return;

      this.time = Math.min(Math.max(strip.scrollLeft / this.pxPerSec, 0), this.duration);
      this.seek(this.time);
    },

    /** Move to the middle of the adjacent frame */
    /** Mouse wheels scroll the strip sideways */
    onWheel(e: WheelEvent) {
      this.refs().strip?.scrollBy({ left: e.deltaX + e.deltaY });
    },

    step(dir: number) {
      const frame = Math.floor(this.time * this.fps) + dir;
      this.time = Math.min(Math.max((frame + 0.5) / this.fps, 0), this.duration);
      this.scrollToTime();
      this.seek(this.time);
    },

    scrollToTime() {
      const strip = this.refs().strip;
      if (strip) strip.scrollLeft = this.time * this.pxPerSec;
    },

    /** Seek without queueing up more than one pending seek */
    seek(time: number) {
      this.pendingSeek = time;
      if (!this.seeking) this.flushSeek();
    },

    flushSeek() {
      const time = this.pendingSeek;
      this.pendingSeek = null;
      if (time === null) return;
      this.seeking = true;
      this.player.currentTime = time;
    },

    onSeeked() {
      this.seeking = false;
      this.flushSeek();
    },

    onDurationChange() {
      this.duration = this.player.duration || 0;
    },

    onResize() {
      this.stripWidth = this.refs().strip?.clientWidth ?? 0;
      this.$nextTick(() => this.scrollToTime());
    },

    /** Keep viewer and player shortcuts (e.g. delete) away while capturing */
    onKeydown(e: KeyboardEvent) {
      e.stopPropagation();
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        this.step(e.key === 'ArrowLeft' ? -1 : 1);
      } else if (e.key === 'Escape') {
        e.preventDefault();
        this.close();
      }
    },

    async save() {
      if (this.saving) return;
      this.saving = true;

      // ffmpeg picks the first frame at or after the time, so aim just before the one on screen
      const time = this.frameTime !== null ? Math.max(this.frameTime - 0.001, 0) : this.time;

      try {
        const res = await axios.post<{ fileid: number; folder: string | null }>(API.VIDEO_FRAME(this.photo.fileid), {
          time,
        });
        const { fileid, folder } = res.data;

        // Keep the frame next to the video in the album
        const { user, name } = this.$route.params;
        if (this.routeIsAlbums && user && name) {
          for await (const _ of dav.addToAlbum(String(user), String(name), [{ fileid } as IPhoto])) {
            // Drain the generator
          }
        }

        showSuccess(this.t('memories', 'Frame saved to {folder}', { folder: folder || '/' }));
        utils.bus.emit('files:file:created', { fileid });
      } catch (e: any) {
        showError(
          this.t('memories', 'Failed to save frame: {error}', {
            error: e?.response?.data?.message ?? e?.message ?? String(e),
          }),
        );
      } finally {
        this.saving = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.frame-capture {
  position: absolute;
  inset: 0;
  z-index: 100003;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  pointer-events: auto;
  color: white;

  > .top,
  > .bottom {
    background: rgba(0, 0, 0, 0.75);
    padding: 8px 12px;
    padding-bottom: calc(8px + env(safe-area-inset-bottom, 0px));
  }

  > .top {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-top: calc(8px + env(safe-area-inset-top, 0px));

    .title {
      flex: 1;
      font-weight: 600;
      font-size: 1.1em;
    }

    :deep(.button-vue--tertiary-no-background) {
      color: white;
    }
  }

  > .bottom {
    .time {
      text-align: center;
      font-variant-numeric: tabular-nums;
      margin-bottom: 6px;
    }

    .controls {
      display: flex;
      align-items: center;
      gap: 4px;

      :deep(.button-vue) {
        color: white;
      }
    }
  }

  .strip-outer {
    position: relative;
    flex: 1;
    min-width: 0;
  }

  .strip {
    display: flex; // track margins count towards the scroll width
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
    touch-action: pan-x;
    &::-webkit-scrollbar {
      display: none;
    }
  }

  .track {
    flex: none;
    position: relative;
    height: 48px; // STRIP_HEIGHT
    border-radius: 4px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.15);
  }

  .cue {
    position: absolute;
    top: 0;
    height: 100%;
    display: flex;
    overflow: hidden;
  }

  .thumb {
    flex-shrink: 0;
    height: 100%;
    overflow: hidden;
  }

  .sprite {
    transform-origin: 0 0;
  }

  .cursor {
    position: absolute;
    top: -4px;
    bottom: -4px;
    left: 50%;
    width: 3px;
    margin-left: -1.5px;
    border-radius: 2px;
    background: white;
    box-shadow: 0 0 4px rgba(0, 0, 0, 0.6);
    pointer-events: none;
  }
}
</style>
