import { expect, test } from 'playwright/test';
import {
  operationalMapAutoFitPoints,
  type OperationalMapIncidentModel,
  type OperationalMapLayerModels,
  type OperationalMapLayerVisibility,
} from '../src/features/incidents/OperationalMapCanvas';

const visibleLayers: OperationalMapLayerVisibility = {
  commandCenters: true,
  historicalIncidents: true,
  pilotHomes: true,
};

const layers: OperationalMapLayerModels = {
  commandCenters: [{ id: 'center', name: 'Meldkamer', latitude: 53.2, longitude: 6.5 }],
  historicalIncidents: [{
    id: 'history',
    reference: 'DIS-100',
    title: 'Afgesloten inzet',
    latitude: 51.4,
    longitude: 3.8,
  }],
  pilotHomes: [{
    id: 'home',
    name: 'Piloot thuis',
    homeCity: 'Maastricht',
    latitude: 50.85,
    longitude: 5.69,
  }],
};

function activeIncident(pilotLatitude = 52.105): OperationalMapIncidentModel {
  return {
    incident: { id: 'incident', title: 'Actieve inzet' },
    color: '#fbbf24',
    incidentPoint: { latitude: 52.1, longitude: 5.1 },
    liveLocations: [{
      userId: 'pilot',
      name: 'Actuele piloot',
      color: '#7dd3fc',
      latitude: pilotLatitude,
      longitude: 5.11,
      operationalStatus: 'en_route',
      etaMinutes: 6,
      route: {
        points: [
          { latitude: pilotLatitude, longitude: 5.11 },
          { latitude: 52.102, longitude: 5.105 },
          { latitude: 52.1, longitude: 5.1 },
        ],
        source: 'navigation',
        distanceMeters: 1_200,
        durationSeconds: 360,
      },
    }],
  };
}

test('frames active incident, current pilots and route without widening for reference layers', () => {
  const model = activeIncident();
  const points = operationalMapAutoFitPoints({
    models: [model],
    layers,
    layerVisibility: visibleLayers,
    showRoutes: true,
    allowReferenceLayerFallback: false,
  });

  expect(points).toEqual([
    model.incidentPoint,
    model.liveLocations[0],
    ...model.liveLocations[0].route!.points,
  ]);
  expect(points).not.toContainEqual(layers.commandCenters[0]);
  expect(points).not.toContainEqual(layers.historicalIncidents[0]);
  expect(points).not.toContainEqual(layers.pilotHomes[0]);

  const movedPilotPoints = operationalMapAutoFitPoints({
    models: [activeIncident(52.12)],
    layers,
    layerVisibility: visibleLayers,
    showRoutes: true,
  });
  expect(movedPilotPoints).not.toEqual(points);
  expect(movedPilotPoints).toContainEqual(expect.objectContaining({ latitude: 52.12, longitude: 5.11 }));
});

test('uses visible reference layers only when no operational point is available', () => {
  expect(operationalMapAutoFitPoints({
    models: [],
    layers,
    layerVisibility: {
      commandCenters: true,
      historicalIncidents: false,
      pilotHomes: false,
    },
  })).toEqual(layers.commandCenters);
  expect(operationalMapAutoFitPoints({
    models: [],
    layers,
    layerVisibility: visibleLayers,
    allowReferenceLayerFallback: false,
  })).toEqual([]);

  const withoutRoutes = operationalMapAutoFitPoints({
    models: [activeIncident()],
    layers,
    layerVisibility: visibleLayers,
    showRoutes: false,
  });
  expect(withoutRoutes).toHaveLength(2);
  expect(withoutRoutes).toEqual([
    activeIncident().incidentPoint,
    activeIncident().liveLocations[0],
  ]);
});
