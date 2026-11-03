import { describe, expect, it } from 'vitest'
import { parseLocation, tilePosition } from '../../../examples/extensions/comet-essentials/blocks/maps/view.js'

describe('Comet Essentials Maps coordinates', () => {
  it('accepts decimal coordinate pairs, including the equator and date line', () => {
    expect(parseLocation(' 52.520008, 13.404954 ')).toEqual({ latitude: 52.520008, longitude: 13.404954 })
    expect(parseLocation('0, 0')).toEqual({ latitude: 0, longitude: 0 })
    expect(parseLocation('-33.86, +151.21')).toEqual({ latitude: -33.86, longitude: 151.21 })
    expect(parseLocation('0, -180')).toEqual({ latitude: 0, longitude: -180 })
    expect(parseLocation('0, 180')).toEqual({ latitude: 0, longitude: 180 })
  })

  it('rejects names, incomplete pairs, non-finite values and points beyond the map extent', () => {
    for (const value of ['', null, 'Berlin', '52.5', '52.5,', ', 13.4', '90, 0', '-86, 0', '0, 181', 'NaN, 0', 'Infinity, 0', '0, 0, 1', '0, 0<script>']) {
      expect(parseLocation(value)).toBeNull()
    }
  })

  it('projects coordinates to finite tiles at world and street zooms', () => {
    expect(tilePosition(parseLocation('0, 0'), 1)).toEqual({ x: 1, y: 1 })
    expect(tilePosition(parseLocation('0, -180'), 19).x).toBe(0)
    expect(tilePosition(parseLocation('0, 180'), 19).x).toBe(2 ** 19)
    const berlin = tilePosition(parseLocation('52.520008, 13.404954'), 13)
    expect(Math.floor(berlin.x)).toBe(4401)
    expect(Math.floor(berlin.y)).toBe(2686)
    for (const latitude of [-85.05112878, 85.05112878]) {
      const position = tilePosition({ latitude, longitude: 180 }, 19)
      expect(Number.isFinite(position.y)).toBe(true)
      expect(position.y).toBeGreaterThanOrEqual(0)
      expect(position.y).toBeLessThanOrEqual(2 ** 19)
    }
  })
})
