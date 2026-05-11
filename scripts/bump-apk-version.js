import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const rootDir = resolve(fileURLToPath(new URL('..', import.meta.url)))
const gradlePath = resolve(rootDir, 'android/app/build.gradle')
const downloadsDir = resolve(rootDir, 'downloads')
const publicDir = resolve(rootDir, 'public')
const versionJsonPath = resolve(publicDir, 'version.json')
const isDryRun = process.argv.includes('--dry-run')
const apkBaseUrl = (process.env.PLANRUN_APK_BASE_URL || 'https://planrun.ru/downloads').replace(/\/$/, '')
const forceUpdate = process.env.PLANRUN_FORCE_UPDATE === '1'

function versionNameToCode(versionName) {
  const match = String(versionName || '').match(/^(\d+)\.(\d+)$/)
  if (!match) return 0
  return Number(match[1]) * 10 + Number(match[2])
}

function codeToVersionName(versionCode) {
  return `${Math.floor(versionCode / 10)}.${versionCode % 10}`
}

function readGradleVersionCode() {
  if (!existsSync(gradlePath)) return 0
  const gradle = readFileSync(gradlePath, 'utf8')
  const codeMatch = gradle.match(/versionCode\s+(\d+)/)
  const nameMatch = gradle.match(/versionName\s+"([^"]+)"/)
  return Math.max(
    codeMatch ? Number(codeMatch[1]) : 0,
    nameMatch ? versionNameToCode(nameMatch[1]) : 0,
  )
}

function readPublicVersionCode() {
  if (!existsSync(versionJsonPath)) return 0
  try {
    const data = JSON.parse(readFileSync(versionJsonPath, 'utf8'))
    return Number(data.version_code) || versionNameToCode(data.version)
  } catch {
    return 0
  }
}

function readDownloadsVersionCode() {
  if (!existsSync(downloadsDir)) return 0
  return readdirSync(downloadsDir)
    .map((name) => name.match(/^planrun-(\d+\.\d+)\.apk$/)?.[1])
    .filter(Boolean)
    .map(versionNameToCode)
    .reduce((max, code) => Math.max(max, code), 0)
}

const currentMaxCode = Math.max(
  readGradleVersionCode(),
  readPublicVersionCode(),
  readDownloadsVersionCode(),
)
const nextVersionCode = currentMaxCode + 1
const nextVersionName = codeToVersionName(nextVersionCode)
const versionInfo = {
  version_code: nextVersionCode,
  version: nextVersionName,
  download_url: `${apkBaseUrl}/planrun-${nextVersionName}.apk`,
  force_update: forceUpdate,
}

if (!isDryRun) {
  const gradle = readFileSync(gradlePath, 'utf8')
    .replace(/versionCode\s+\d+/, `versionCode ${nextVersionCode}`)
    .replace(/versionName\s+"[^"]+"/, `versionName "${nextVersionName}"`)

  mkdirSync(publicDir, { recursive: true })
  writeFileSync(gradlePath, gradle)
  writeFileSync(versionJsonPath, `${JSON.stringify(versionInfo, null, 2)}\n`)
}

console.log(
  `[apk] ${isDryRun ? 'would prepare' : 'prepared'} version ${nextVersionName} (code ${nextVersionCode})`,
)
console.log(`[apk] update manifest: ${JSON.stringify(versionInfo)}`)
