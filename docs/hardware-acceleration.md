# Hardware-accelerated clip encoding

NNTmux can use VAAPI or Intel Quick Sync Video (QSV) when a downloaded video head must be transcoded into a browser-safe H.264/AAC Clip. Hardware acceleration is opt-in. Browser-safe sources still use stream copy, and any failed hardware attempt automatically retries with the existing software encoder.

The stock Debian and Ubuntu `ffmpeg` packages include the VAAPI and QSV support needed here. Do not compile a custom ffmpeg build for this feature.

## Install the Linux media stack

On an Intel iGPU host, start with the distribution packages:

```bash
sudo apt update
sudo apt install ffmpeg vainfo intel-media-va-driver libvpl2
```

Some Debian/Ubuntu configurations provide the full Intel media driver as `intel-media-va-driver-non-free` instead. Use the package appropriate to the distribution's enabled repositories.

Very new Intel GPU generations, including Arrow Lake, may need a newer iHD userspace driver and kernel than the distribution originally shipped. Apply normal distribution updates first. On Ubuntu 24.04, the [HWE stack](https://ubuntu.com/kernel/lifecycle) supplies a newer supported kernel:

```bash
sudo apt install --install-recommends linux-generic-hwe-24.04
```

If the updated Ubuntu packages still do not recognize the GPU, follow Intel's current [Ubuntu Intel Graphics PPA instructions](https://dgpu-docs.intel.com/installation-guides/installing-packages-from-the-intel-ppa.html) to obtain a newer `intel-media-va-driver-non-free`/iHD and oneVPL stack. Do not mix repositories or replace working distro drivers without first recording the installed package versions and a rollback path.

VAAPI is vendor-neutral on Linux. AMD GPUs use the same `h264_vaapi` backend through Mesa's VAAPI drivers; validate the commands below on that hardware before enabling it. QSV is the Intel-specific backend.

## Grant render-node access

The operating-system user that runs postprocessing must be able to open the configured DRM render node. Add that user to the node's `render` group:

```bash
sudo usermod -aG render <user>
```

Group membership is captured when a process session starts. Log out and back in, or restart the service/container/session that owns postprocessing. For the tmux processing engine, stop it before changing the session and start it again after the new group membership is active:

```bash
php artisan tmux:stop
php artisan tmux:start
```

Confirm both the device ownership and the processing user's groups:

```bash
stat -c '%A %U %G %n' /dev/dri/renderD128
groups <user>
```

## Verify before enabling

Run the checks as the same user that runs postprocessing. First verify VAAPI can open the render node and lists an H.264 encode entry point:

```bash
vainfo --display drm --device /dev/dri/renderD128
```

Then run the test for the backend you intend to enable. Each command generates frames locally, uploads them to the selected device, and discards the encoded output:

```bash
ffmpeg -hide_banner -init_hw_device vaapi=va:/dev/dri/renderD128 -filter_hw_device va -f lavfi -i testsrc2=size=1280x720:rate=30 -vf 'format=nv12,hwupload' -frames:v 60 -c:v h264_vaapi -f null -
```

```bash
ffmpeg -hide_banner -init_hw_device qsv=qsv:hw,child_device=/dev/dri/renderD128 -filter_hw_device qsv -f lavfi -i testsrc2=size=1280x720:rate=30 -vf 'format=nv12,hwupload=extra_hw_frames=64' -frames:v 60 -c:v h264_qsv -f null -
```

Do not enable hardware acceleration until the chosen command exits successfully.

## Configure NNTmux

The default is fully off: NNTmux does not initialize a hardware device or add hardware flags to ffmpeg commands.

```env
# off | vaapi | qsv
NNTMUX_CLIP_HWACCEL=vaapi
NNTMUX_CLIP_HWACCEL_DEVICE=/dev/dri/renderD128
```

After changing cached production configuration, rebuild the configuration cache and restart the postprocessing engine so workers receive the new values.

An unknown backend id is ignored and logged at debug level. If an enabled backend cannot initialize its device, its driver refuses the encode, the process throws, or it produces no output, NNTmux logs the hardware failure and immediately retries the Clip with `libx264`. A hardware configuration problem therefore does not discard an otherwise decodable Clip.
